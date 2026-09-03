<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * Écarte les résultats SearXNG manifestement hors-sujet avant qu'ils n'atteignent le pipeline.
 *
 * La détection se fait en deux passes sur le texte concaténé (titre + URL + description) :
 *   1. Présence d'un pattern bloquant (tutoriels, docs, Wikipedia…) → rejeté
 *   2. Absence de tout signal emploi (job, emploi, freelance, CDI…) → rejeté
 *
 * Un résultat sans pattern bloquant mais avec au moins un signal emploi est
 * considéré comme potentiellement pertinent.
 *
 * Les deux listes sont surchargées à l'exécution par `app.profile.searx_blocked_patterns`
 * et `app.profile.searx_job_signals` (voir `config/packages/jobscan.yaml`). Les constantes
 * ci-dessous ne servent que de repli hors conteneur (tests, instanciation directe).
 */
final readonly class SearxNoiseFilter
{
    /** @var list<string> */
    public const array DEFAULT_BLOCKED_PATTERNS = [
        'tutorial',
        'cours',
        'formation',
        'manual',
        'documentation',
        'wikipedia',
        'youtube.com',
        'openclassrooms.com',
        'w3schools.com',
        'geeksforgeeks.org',
        'php.net',
        'github.com/php',
    ];

    /** @var list<string> */
    public const array DEFAULT_JOB_SIGNALS = [
        'job',
        'jobs',
        'emploi',
        'emplois',
        'recrute',
        'hiring',
        'remote',
        'freelance',
        'mission',
        'cdi',
        'developer',
        'développeur',
        'backend',
        'full stack',
        'fullstack',
    ];

    /**
     * @param list<string> $blockedPatterns
     * @param list<string> $jobSignals
     */
    public function __construct(
        private array $blockedPatterns = self::DEFAULT_BLOCKED_PATTERNS,
        private array $jobSignals = self::DEFAULT_JOB_SIGNALS,
    ) {
    }

    public function isClearlyIrrelevant(string $title, string $url, string $description): bool
    {
        $text = strtolower($title . ' ' . $url . ' ' . $description);

        foreach ($this->blockedPatterns as $pattern) {
            if ($pattern !== '' && str_contains($text, $pattern)) {
                return true;
            }
        }

        return array_all(
            $this->jobSignals,
            static fn (string $signal): bool => $signal === '' || !str_contains($text, $signal),
        );
    }
}
