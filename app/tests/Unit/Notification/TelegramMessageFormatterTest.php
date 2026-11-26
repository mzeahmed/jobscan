<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\DigestItem;
use App\Matching\CriterionStatus;
use App\AI\Extraction\SalaryRange;
use App\Profile\Model\SalaryPeriod;
use App\AI\Extraction\JobRequirements;
use App\DTO\PublicationDateConfidence;
use App\AI\Extraction\ExtractionSource;
use App\Notification\NotificationLevel;
use App\Notification\TelegramMessageFormatter;

final class TelegramMessageFormatterTest extends NotificationTestCase
{
    private TelegramMessageFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new TelegramMessageFormatter();
    }

    public function testAPriorityMessageHoldsEveryAvailableField(): void
    {
        $job = $this->job(publishedAt: $this->now->modify('-3 hours'));
        $match = $this->match($job, 92, 100, 94, [
            $this->criterion('required_skills', CriterionStatus::Match, 1.0, 40, '2/2 compétences exigées couvertes.'),
            $this->criterion('title', CriterionStatus::Match, 1.0, 15, 'L\'intitulé recherché « Développeur back-end » figure dans le titre.'),
            $this->criterion('remote', CriterionStatus::Match, 1.0, 5, 'Mode de travail préféré : remote.'),
        ], ['Kubernetes'], ['Type de contrat non précisé.']);

        $message = $this->formatter->immediate($match, NotificationLevel::Priority, 'Backend PHP / Symfony', 'CV Backend PHP', $this->now);

        foreach ([
            '🔥 <b>Opportunité prioritaire</b>',
            '<b>Développeur back-end PHP</b>',
            'Exemple SAS · Marseille',
            'Contrat : cdi',
            'Télétravail : complet',
            $this->nbsp('Salaire : 50 000–60 000 EUR/an'),
            'Publiée le 01/12/2026 · il y a 3 h',
            'Compatibilité <b>92</b>/100',
            'Fraîcheur <b>100</b>/100',
            'Priorité <b>94</b>/100',
            'Profil : Backend PHP / Symfony · CV : CV Backend PHP',
            '✓ 2/2 compétences exigées couvertes.',
            '✗ Manque : Kubernetes',
            '? 1 information(s) non précisée(s) par l&#039;annonce.',
            '<a href="https://jobs.example/offre-1">Voir l&#039;offre</a>',
        ] as $expected) {
            self::assertStringContainsString($expected, $message, $expected);
        }
    }

    public function testUnknownFieldsAreOmittedNotFilledWithDefaults(): void
    {
        $job = $this->job(requirements: new JobRequirements(requiredSkills: ['PHP']), company: null, location: null);

        $message = $this->formatter->immediate($this->match($job, cv: null), NotificationLevel::Interesting, 'Backend', null, $this->now);

        foreach (['Contrat', 'Télétravail', 'Salaire', ' · CV', 'Exemple SAS'] as $absent) {
            self::assertStringNotContainsString($absent, $message, $absent);
        }

        self::assertStringContainsString('⭐ <b>Offre intéressante</b>', $message);
        self::assertStringContainsString('Profil : Backend', $message);
    }

    public function testThePublicationDateIsDistinguishedFromTheDetectionDate(): void
    {
        $known = $this->job(publishedAt: $this->now->modify('-2 days'));
        $unknown = $this->job();
        $unreliable = $this->job(publishedAt: $this->now->modify('-5 hours'), confidence: PublicationDateConfidence::Low);

        $knownMessage = $this->formatter->immediate($this->match($known), NotificationLevel::Priority, 'p', null, $this->now);
        $unknownMessage = $this->formatter->immediate($this->match($unknown), NotificationLevel::Priority, 'p', null, $this->now);
        $unreliableMessage = $this->formatter->immediate($this->match($unreliable), NotificationLevel::Priority, 'p', null, $this->now);

        self::assertStringContainsString('Publiée le 29/11/2026 · il y a 2 j', $knownMessage);
        self::assertStringContainsString('Publication inconnue · détectée le ', $unknownMessage);
        self::assertStringNotContainsString('Publiée le', $unknownMessage, 'Une offre détectée aujourd\'hui n\'est pas publiée aujourd\'hui.');
        self::assertStringContainsString('(date peu fiable)', $unreliableMessage);
    }

    public function testAFuturePublicationDateIsTreatedAsUnknown(): void
    {
        $job = $this->job(publishedAt: $this->now->modify('+3 days'));

        $message = $this->formatter->immediate($this->match($job), NotificationLevel::Priority, 'p', null, $this->now);

        self::assertStringContainsString('Publication inconnue', $message);
    }

    /** @return iterable<string, array{SalaryRange, string}> */
    public static function salaries(): iterable
    {
        yield 'fourchette annuelle' => [new SalaryRange(45000, 55000, 'EUR', SalaryPeriod::Annual), 'Salaire : 45 000–55 000 EUR/an'];
        yield 'TJM' => [new SalaryRange(500, 650, 'EUR', SalaryPeriod::Daily), 'Salaire : 500–650 EUR/j'];
        yield 'minimum seul' => [new SalaryRange(60000, null, 'EUR', SalaryPeriod::Annual), 'Salaire : à partir de 60 000 EUR/an'];
        yield 'maximum seul' => [new SalaryRange(null, 70000, 'USD', SalaryPeriod::Annual), 'Salaire : jusqu\'à 70 000 USD/an'];
        yield 'montant unique' => [new SalaryRange(550, 550, 'EUR', SalaryPeriod::Daily), 'Salaire : 550 EUR/j'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('salaries')]
    public function testFormatsTheAnnouncedSalaryAsWritten(SalaryRange $salary, string $expected): void
    {
        $job = $this->job(requirements: new JobRequirements(salary: $salary));

        $message = html_entity_decode($this->formatter->immediate($this->match($job), NotificationLevel::Priority, 'p', null, $this->now), ENT_QUOTES | ENT_HTML401);

        self::assertStringContainsString($this->nbsp($expected), $message);
    }

    public function testTextComingFromAnAdvertIsEscaped(): void
    {
        $job = $this->job('Ingénieur d\'affaires <b>"PHP"</b> & Co', company: 'Dupont & Fils <script>', location: 'Aix-en-Provence', url: 'https://jobs.example/o?a=1&b="x"');

        $message = $this->formatter->immediate($this->match($job, missing: ['C++ <i>']), NotificationLevel::Priority, 'Profil <x>', 'CV "A"', $this->now);

        self::assertStringContainsString('Ingénieur d&#039;affaires &lt;b&gt;&quot;PHP&quot;&lt;/b&gt; &amp; Co', $message);
        self::assertStringContainsString('Dupont &amp; Fils &lt;script&gt;', $message);
        self::assertStringContainsString('Profil &lt;x&gt; · CV : CV &quot;A&quot;', $message);
        self::assertStringContainsString('C++ &lt;i&gt;', $message);
        self::assertStringContainsString('href="https://jobs.example/o?a=1&amp;b=&quot;x&quot;"', $message);
        self::assertStringNotContainsString('<script', $message);
        self::assertStringNotContainsString('&apos;', $message);
        $this->assertTagsBalanced($message);
    }

    public function testAnUnsafeOfferUrlIsNeverMadeClickable(): void
    {
        $job = $this->job(url: 'javascript:alert(1)');

        $message = $this->formatter->immediate($this->match($job), NotificationLevel::Priority, 'p', null, $this->now);

        self::assertStringNotContainsString('<a ', $message);
        self::assertStringNotContainsString('javascript:', $message);
    }

    public function testTheMessageNeverExceedsTelegramsLimit(): void
    {
        $long = str_repeat('Développeur Senior Full Stack ', 40);
        $job = $this->job($long, company: $long, location: $long, url: 'https://jobs.example/' . str_repeat('a', 1500));
        $criteria = array_map(fn (int $i) => $this->criterion('title', CriterionStatus::Match, 1.0, 10 + $i, str_repeat('détail très long ', 30)), range(1, 12));
        $match = $this->match($job, criteria: $criteria, missing: array_map(static fn (int $i): string => str_repeat('Compétence', 20) . $i, range(1, 20)), unknown: array_fill(0, 30, 'x'));

        $message = $this->formatter->immediate($match, NotificationLevel::Priority, $long, $long, $this->now);

        self::assertLessThan(4096, mb_strlen($message));
        $this->assertTagsBalanced($message);
    }

    public function testTheReasonsAreTheHeaviestSatisfiedCriteria(): void
    {
        $job = $this->job();
        $match = $this->match($job, criteria: [
            $this->criterion('remote', CriterionStatus::Match, 1.0, 5, 'RAISON-REMOTE'),
            $this->criterion('required_skills', CriterionStatus::Match, 1.0, 40, 'RAISON-COMPETENCES'),
            $this->criterion('title', CriterionStatus::Partial, 0.7, 15, 'RAISON-TITRE'),
            $this->criterion('contract', CriterionStatus::Match, 1.0, 5, 'RAISON-CONTRAT'),
            $this->criterion('location', CriterionStatus::Mismatch, 0.0, 10, 'RAISON-LIEU-ECHEC'),
            $this->criterion('salary', CriterionStatus::Unknown, 0.0, 5, 'RAISON-SALAIRE-INCONNU'),
            $this->criterion('availability', CriterionStatus::Match, 1.0, 100, 'RAISON-DISPONIBILITE'),
        ]);

        $message = $this->formatter->immediate($match, NotificationLevel::Priority, 'p', null, $this->now);

        self::assertStringContainsString('RAISON-COMPETENCES', $message);
        self::assertStringContainsString('RAISON-TITRE', $message);
        self::assertTrue(
            str_contains($message, 'RAISON-REMOTE') xor str_contains($message, 'RAISON-CONTRAT'),
            'Seules les trois raisons qui pèsent le plus sont reprises.',
        );
        self::assertStringNotContainsString('RAISON-LIEU-ECHEC', $message, 'Un échec n\'est pas une raison du matching.');
        self::assertStringNotContainsString('RAISON-SALAIRE-INCONNU', $message);
        self::assertStringNotContainsString('RAISON-DISPONIBILITE', $message);
    }

    public function testMissingSkillsAreCappedWithAnEllipsis(): void
    {
        $match = $this->match($this->job(), missing: ['A', 'B', 'C', 'D', 'E']);

        $message = $this->formatter->immediate($match, NotificationLevel::Priority, 'p', null, $this->now);

        self::assertStringContainsString('✗ Manque : A, B, C…', $message);
        self::assertStringNotContainsString('D', explode('✗ Manque : ', $message)[1] === '' ? '' : explode("\n", explode('✗ Manque : ', $message)[1])[0]);
    }

    public function testAnHeuristicAnalysisIsDisclosed(): void
    {
        $match = $this->match($this->job(), source: ExtractionSource::Heuristic);

        $message = $this->formatter->immediate($match, NotificationLevel::Interesting, 'p', null, $this->now);

        self::assertStringContainsString('Analyse heuristique', $message);
    }

    public function testNothingIsShownAboutReasonsWhenTheResultIsUnreadable(): void
    {
        $job = $this->job();
        $match = new \App\Entity\JobMatch($job, 'p', $this->match($job)->getResult() ?? throw new \LogicException(), 80, 80, 'fp', 1, $this->now);
        new \ReflectionProperty($match, 'details')->setValue($match, ['corrompu' => true]);

        $message = $this->formatter->immediate($match, NotificationLevel::Priority, 'p', null, $this->now);

        self::assertStringContainsString('Compatibilité', $message);
        self::assertStringNotContainsString('✓', $message);
    }

    // --- Récapitulatif --------------------------------------------------------------------------

    public function testTheDigestGroupsOffersByLevel(): void
    {
        $interesting = new DigestItem($this->match($this->job('Offre intéressante', url: 'https://jobs.example/1'), priority: 72), NotificationLevel::Interesting, 'Backend');
        $secondary = new DigestItem($this->match($this->job('Offre secondaire', url: 'https://jobs.example/2'), priority: 48), NotificationLevel::Secondary, 'Backend');

        $digest = $this->formatter->digest([$secondary, $interesting], $this->now);

        self::assertSame(2, $digest->included);
        self::assertStringContainsString('📋 <b>Récapitulatif — 2 offre(s) à examiner</b>', $digest->text);
        self::assertLessThan(strpos($digest->text, 'Offre secondaire'), strpos($digest->text, 'Offre intéressante'), 'Les plus importantes d\'abord.');
        self::assertStringContainsString('Priorité 72', $digest->text);
        self::assertStringContainsString('<a href="https://jobs.example/1">voir</a>', $digest->text);
        self::assertStringContainsString('⭐ Offres intéressantes', $digest->text);
        self::assertStringContainsString('📌 Offres secondaires', $digest->text);
    }

    public function testTheDigestEscapesAndOmitsUnknownFields(): void
    {
        $job = $this->job('Chef d\'équipe <PHP>', company: null, location: null);

        $digest = $this->formatter->digest([new DigestItem($this->match($job, priority: 50), NotificationLevel::Secondary, 'p')], $this->now);

        self::assertStringContainsString('Chef d&#039;équipe &lt;PHP&gt;', $digest->text);
        self::assertStringContainsString('publication inconnue', $digest->text);
        self::assertStringNotContainsString('</b> — ', $digest->text, 'Entreprise et lieu inconnus : rien n\'est affiché après le titre.');
        $this->assertTagsBalanced($digest->text);
    }

    public function testTheDigestStopsBeforeTheLimitAndSaysHowManyRemain(): void
    {
        $items = [];
        foreach (range(1, 60) as $i) {
            $job = $this->job(str_repeat('Titre ', 20) . $i, company: str_repeat('Entreprise ', 8), url: 'https://jobs.example/' . $i);
            $items[] = new DigestItem($this->match($job, priority: 60 - ($i % 10)), NotificationLevel::Secondary, 'p');
        }

        $digest = $this->formatter->digest($items, $this->now);

        self::assertGreaterThan(5, $digest->included);
        self::assertLessThan(60, $digest->included);
        self::assertLessThan(4096, mb_strlen($digest->text));
        self::assertStringContainsString(sprintf('… et %d autre(s) offre(s)', 60 - $digest->included), $digest->text);
        self::assertStringContainsString(sprintf('Récapitulatif — %d offre(s)', $digest->included), $digest->text);
        $this->assertTagsBalanced($digest->text);
    }

    public function testAnEmptyDigestIsEmpty(): void
    {
        self::assertSame(0, $this->formatter->digest([], $this->now)->included);
    }

    private function nbsp(string $text): string
    {
        return (string) preg_replace('/(\d) (\d{3})/', "$1\u{00A0}$2", $text);
    }

    private function assertTagsBalanced(string $html): void
    {
        foreach (['b', 'a'] as $tag) {
            self::assertSame(substr_count($html, '<' . $tag . '>') + substr_count($html, '<' . $tag . ' '), substr_count($html, '</' . $tag . '>'), 'Balises <' . $tag . '> déséquilibrées.');
        }
    }
}
