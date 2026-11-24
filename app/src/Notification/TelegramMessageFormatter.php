<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Job;
use App\Entity\JobMatch;
use App\Matching\MatchResult;
use App\Matching\PublicationAge;
use App\Matching\CriterionStatus;
use App\AI\Extraction\SalaryRange;
use App\Profile\Model\SalaryPeriod;
use App\AI\Extraction\ExtractionSource;

/**
 * Met en forme les messages Telegram (HTML) : notification d'une offre, et récapitulatif.
 *
 * - Seules les informations **disponibles** apparaissent : un salaire, un contrat ou une date inconnus sont omis,
 *   jamais remplacés par une valeur par défaut. La date de publication (réelle, parfois inconnue) est distinguée
 *   de la date de détection par Jobscan.
 * - Tout texte venant d'une annonce est échappé (`TelegramHtml`) et chaque champ est borné : un message ne dépasse
 *   jamais la limite de Telegram (4096 caractères).
 */
final readonly class TelegramMessageFormatter
{
    /** Marge sous la limite de 4096 caractères de Telegram. */
    private const int MAX_LENGTH = 3900;
    private const int MAX_REASONS = 3;
    private const int MAX_MISSING_SKILLS = 3;
    private const int MAX_DIGEST_FOOTER = 200;

    private const array EMOJI = [
        'priority' => '🔥',
        'interesting' => '⭐',
        'secondary' => '📌',
    ];

    private const array REMOTE_LABELS = ['remote' => 'complet', 'hybrid' => 'partiel', 'onsite' => 'sur site'];

    public function immediate(JobMatch $match, NotificationLevel $level, string $profileName, ?string $cvLabel, \DateTimeImmutable $now): string
    {
        $message = $this->immediateMessage($match, $level, $profileName, $cvLabel, $now, true);

        // Ceinture et bretelles : chaque champ est déjà borné, mais on ne risque jamais un message tronqué en plein balisage.
        if (mb_strlen($message) > self::MAX_LENGTH) {
            $message = $this->immediateMessage($match, $level, $profileName, $cvLabel, $now, false);
        }

        return $message;
    }

    /**
     * Récapitulatif : les offres sont reprises tant que le message tient dans la limite de Telegram.
     *
     * @param list<DigestItem> $items Du plus prioritaire au moins prioritaire
     */
    public function digest(array $items, \DateTimeImmutable $now): DigestMessage
    {
        $sections = [];
        $length = 0;
        $included = 0;

        foreach ([NotificationLevel::Priority, NotificationLevel::Interesting, NotificationLevel::Secondary] as $level) {
            foreach ($items as $item) {
                if ($item->level !== $level) {
                    continue;
                }

                $block = $this->digestItem($item, $now);
                if ($length + mb_strlen($block) + self::MAX_DIGEST_FOOTER > self::MAX_LENGTH) {
                    break 2;
                }

                $sections[$level->value][] = $block;
                $length += mb_strlen($block) + 1;
                ++$included;
            }
        }

        $titles = [
            'priority' => '🔥 Opportunités prioritaires',
            'interesting' => '⭐ Offres intéressantes',
            'secondary' => '📌 Offres secondaires',
        ];
        $lines = [sprintf('📋 <b>Récapitulatif — %d offre(s) à examiner</b>', $included)];
        foreach ($titles as $key => $title) {
            if (isset($sections[$key])) {
                $lines[] = '';
                $lines[] = sprintf('<b>%s</b>', $title);
                array_push($lines, ...$sections[$key]);
            }
        }

        $remaining = count($items) - $included;
        if ($remaining > 0) {
            $lines[] = '';
            $lines[] = TelegramHtml::escape(sprintf('… et %d autre(s) offre(s) dans le prochain récapitulatif (php bin/console app:jobs:priority).', $remaining));
        }

        return new DigestMessage(implode("\n", $lines), $included);
    }

    private function immediateMessage(JobMatch $match, NotificationLevel $level, string $profileName, ?string $cvLabel, \DateTimeImmutable $now, bool $withReasons): string
    {
        $job = $match->getJob();
        $result = $match->getResult();
        $requirements = $job->getRequirements();

        $lines = [
            sprintf('%s <b>%s</b>', self::EMOJI[$level->value], TelegramHtml::escape($level->label())),
            sprintf('<b>%s</b>', TelegramHtml::escape($this->cut((string) $job->getTitle(), 200))),
        ];

        $where = array_filter([$this->cut((string) $job->getCompany(), 80), $this->cut((string) $job->getLocation(), 80)], static fn (string $part): bool => $part !== '');
        if ($where !== []) {
            $lines[] = TelegramHtml::escape(implode(' · ', $where));
        }

        $facts = [];
        if ($requirements !== null && $requirements->contractTypes !== []) {
            $facts[] = 'Contrat : ' . implode(', ', array_map(static fn ($type): string => $type->value, $requirements->contractTypes));
        }
        if ($requirements?->remoteMode !== null) {
            $facts[] = 'Télétravail : ' . self::REMOTE_LABELS[$requirements->remoteMode->value];
        }
        if ($requirements?->salary !== null) {
            $facts[] = 'Salaire : ' . $this->salary($requirements->salary);
        }
        if ($facts !== []) {
            $lines[] = TelegramHtml::escape(implode(' · ', $facts));
        }

        $lines[] = TelegramHtml::escape($this->publication($job, $now));
        $lines[] = '';
        $lines[] = sprintf(
            'Compatibilité <b>%d</b>/100 · Fraîcheur <b>%d</b>/100 · Priorité <b>%d</b>/100',
            $match->getMatchScore(),
            $match->getFreshnessScore(),
            $match->getPriorityScore(),
        );
        $lines[] = TelegramHtml::escape('Profil : ' . $this->cut($profileName, 60) . ($cvLabel === null ? '' : ' · CV : ' . $this->cut($cvLabel, 60)));

        if ($withReasons && $result !== null) {
            $reasons = $this->reasons($result);
            if ($reasons !== []) {
                $lines[] = '';
                array_push($lines, ...$reasons);
            }
        }

        if ($result?->requirementsSource === ExtractionSource::Heuristic) {
            $lines[] = '';
            $lines[] = TelegramHtml::escape('ℹ️ Analyse heuristique : les compétences exigées n\'ont pas pu être déterminées.');
        }

        $lines[] = '';
        $lines[] = TelegramHtml::link((string) $job->getUrl(), 'Voir l\'offre');

        return implode("\n", $lines);
    }

    /**
     * Principales raisons du matching : les critères satisfaits qui pèsent le plus, les compétences exigées
     * manquantes, et l'existence d'informations que l'annonce ne donne pas.
     *
     * @return list<string>
     */
    private function reasons(MatchResult $result): array
    {
        $satisfied = array_filter(
            $result->criteria,
            static fn ($criterion): bool => in_array($criterion->status, [CriterionStatus::Match, CriterionStatus::Partial], true)
                && $criterion->weight > 0
                && $criterion->criterion !== 'availability',
        );
        usort($satisfied, static fn ($a, $b): int => ($b->weight * ($b->score ?? 0)) <=> ($a->weight * ($a->score ?? 0)));

        $lines = [];
        foreach (array_slice($satisfied, 0, self::MAX_REASONS) as $criterion) {
            $lines[] = '✓ ' . TelegramHtml::escape($this->cut($criterion->detail, 160));
        }

        if ($result->missingRequiredSkills !== []) {
            $missing = array_map(fn (string $skill): string => $this->cut($skill, 40), array_slice($result->missingRequiredSkills, 0, self::MAX_MISSING_SKILLS));
            $more = count($result->missingRequiredSkills) > self::MAX_MISSING_SKILLS ? '…' : '';
            $lines[] = '✗ ' . TelegramHtml::escape('Manque : ' . implode(', ', $missing) . $more);
        }

        if ($result->unknown !== []) {
            $lines[] = '? ' . TelegramHtml::escape(sprintf('%d information(s) non précisée(s) par l\'annonce.', count($result->unknown)));
        }

        return $lines;
    }

    private function digestItem(DigestItem $item, \DateTimeImmutable $now): string
    {
        $job = $item->match->getJob();
        $head = array_filter([$this->cut((string) $job->getCompany(), 40), $this->cut((string) $job->getLocation(), 40)], static fn (string $part): bool => $part !== '');
        $ago = PublicationAge::ago($job, $now);

        $details = [
            sprintf('Priorité %d', $item->match->getPriorityScore()),
            $ago === null ? 'publication inconnue' : 'il y a ' . $ago,
        ];

        return sprintf(
            "• <b>%s</b>%s\n   %s · %s",
            TelegramHtml::escape($this->cut((string) $job->getTitle(), 80)),
            $head === [] ? '' : ' — ' . TelegramHtml::escape(implode(' · ', $head)),
            TelegramHtml::escape(implode(' · ', $details)),
            TelegramHtml::link((string) $job->getUrl(), 'voir'),
        );
    }

    private function publication(Job $job, \DateTimeImmutable $now): string
    {
        $published = $job->getPublishedAt();
        $ago = PublicationAge::ago($job, $now);

        if ($published === null || $ago === null) {
            return sprintf('Publication inconnue · détectée le %s', $job->getFirstSeenAt()->format('d/m/Y à H\hi'));
        }

        $unreliable = str_contains(PublicationAge::describe($job, $now), 'peu fiable') ? ' (date peu fiable)' : '';

        return sprintf('Publiée le %s · il y a %s%s', $published->format('d/m/Y'), $ago, $unreliable);
    }

    private function salary(SalaryRange $salary): string
    {
        $format = static fn (int $amount): string => number_format($amount, 0, ',', "\u{00A0}");
        $suffix = sprintf('%s/%s', $salary->currency, $salary->period === SalaryPeriod::Daily ? 'j' : 'an');

        return match (true) {
            $salary->min !== null && $salary->max !== null && $salary->min !== $salary->max => sprintf('%s–%s %s', $format($salary->min), $format($salary->max), $suffix),
            $salary->min !== null && $salary->max === null => sprintf('à partir de %s %s', $format($salary->min), $suffix),
            $salary->min === null && $salary->max !== null => sprintf('jusqu\'à %s %s', $format($salary->max), $suffix),
            default => sprintf('%s %s', $format((int) ($salary->max ?? $salary->min)), $suffix),
        };
    }

    private function cut(string $text, int $length): string
    {
        return mb_strimwidth(trim($text), 0, $length, '…');
    }
}
