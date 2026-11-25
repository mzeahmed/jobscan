<?php

declare(strict_types=1);

namespace App\Api\Jobs;

use App\Entity\Job;
use App\Api\Shared\SafeUrl;

/**
 * Représentation JSON d'une offre.
 *
 * Le contenu d'une annonce est **non fiable** (texte écrit par un tiers, parfois du HTML) : le front l'affiche comme du
 * texte, jamais comme du HTML ; l'API en retire déjà les balises et ne publie qu'une URL `http(s)`.
 */
final class JobPresenter
{
    private const int EXCERPT_LENGTH = 240;
    private const int DESCRIPTION_LENGTH = 20000;

    /**
     * @param int|null $applicationId candidature suivie pour cette offre, s'il y en a une
     *
     * @return array<string, mixed>
     */
    public static function summary(Job $job, ?int $applicationId): array
    {
        $state = $job->getUserState();

        return [
            'id' => $job->getId(),
            'title' => $job->getTitle(),
            'url' => SafeUrl::http($job->getUrl()),
            'source' => $job->getSource(),
            'company' => $job->getCompany(),
            'location' => $job->getLocation(),
            'remote' => $job->isRemote(),
            'freelance' => $job->isFreelance(),
            'contract_type' => $job->getContractType()->value,
            'seniority' => $job->getSeniority()->value,
            'stack' => $job->getStack(),
            'score' => $job->getScore(),
            'excerpt' => self::text($job->getDescription(), self::EXCERPT_LENGTH),
            'created_at' => $job->getCreatedAt()?->format(DATE_ATOM),
            'published_at' => $job->getPublishedAt()?->format(DATE_ATOM),
            'notified_at' => $job->getNotifiedAt()?->format(DATE_ATOM),
            'user_state' => $state === null ? null : ['value' => $state->value, 'label' => $state->label()],
            'application_id' => $applicationId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Job $job, ?int $applicationId): array
    {
        return self::summary($job, $applicationId) + [
            'description' => self::text($job->getDescription(), self::DESCRIPTION_LENGTH),
            'budget' => $job->getBudget(),
            'score_breakdown' => $job->getScoreBreakdown(),
            'requirements' => $job->getRequirements()?->toArray(),
            'publication' => [
                'source' => $job->getPublicationDateSource()->value,
                'confidence' => $job->getPublicationDateConfidence()->value,
            ],
            'analysis' => [
                'status' => $job->getAnalysisStatus()->value,
                'analyzed_at' => $job->getAnalyzedAt()?->format(DATE_ATOM),
            ],
            'first_seen_at' => $job->getFirstSeenAt()->format(DATE_ATOM),
            'last_seen_at' => $job->getLastSeenAt()->format(DATE_ATOM),
            'probable_duplicate_of_id' => $job->getProbableDuplicateOfId(),
        ];
    }

    /** Texte brut : balises retirées, entités décodées, espaces normalisés, longueur bornée (`…` si coupé). */
    private static function text(?string $html, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)) . '…' : $text;
    }
}
