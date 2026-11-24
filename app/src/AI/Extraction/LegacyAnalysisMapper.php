<?php

declare(strict_types=1);

namespace App\AI\Extraction;

use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use App\Profile\Model\SalaryPeriod;
use App\Profile\Model\RemotePreference;

/**
 * Passerelle entre les exigences structurées et la vue historique (`AiAnalysisDto`) qu'utilise le
 * scoring existant, afin qu'un seul appel LLM alimente les deux.
 *
 * Les conversions sont déterministes et sans estimation : une rémunération n'est exposée que dans la
 * forme que `Scoring` sait lire (`600€/j`, `45-55k€/an`), et uniquement en euros.
 */
final class LegacyAnalysisMapper
{
    public static function toAnalysis(JobRequirements $requirements, bool $recent = false): AiAnalysisDto
    {
        $contracts = $requirements->contractTypes;
        $contract = match (true) {
            in_array(ContractType::Freelance, $contracts, true) => ContractType::Freelance,
            in_array(ContractType::Cdi, $contracts, true) => ContractType::Cdi,
            default => $contracts[0] ?? ContractType::Unknown,
        };

        return new AiAnalysisDto(
            stack: array_values(array_unique(array_map(strtolower(...), $requirements->allSkills()))),
            contractType: $contract,
            freelance: in_array(ContractType::Freelance, $contracts, true),
            // Comme avant : « télétravail mentionné » (complet ou partiel) vaut remote.
            remote: $requirements->remoteMode === RemotePreference::Remote || $requirements->remoteMode === RemotePreference::Hybrid,
            budget: self::budget($requirements->salary),
            recent: $recent,
            seniority: $requirements->seniority,
        );
    }

    /**
     * Exigences minimales dérivées d'une analyse heuristique : les technologies détectées sont
     * seulement « citées » (l'heuristique ne sait pas distinguer exigées et appréciées).
     */
    public static function toRequirements(AiAnalysisDto $analysis): JobRequirements
    {
        return new JobRequirements(
            mentionedSkills: $analysis->stack,
            seniority: $analysis->seniority,
            remoteMode: $analysis->remote ? RemotePreference::Remote : null,
            contractTypes: $analysis->contractType === ContractType::Unknown ? [] : [$analysis->contractType],
            source: ExtractionSource::Heuristic,
        );
    }

    private static function budget(?SalaryRange $salary): string
    {
        if ($salary === null || $salary->currency !== 'EUR') {
            return 'non précisé';
        }

        if ($salary->period === SalaryPeriod::Daily) {
            return ($salary->max ?? $salary->min) . '€/j';
        }

        $min = $salary->min !== null ? self::thousands($salary->min) : null;
        $max = $salary->max !== null ? self::thousands($salary->max) : null;

        return match (true) {
            $min !== null && $max !== null && $min !== $max => $min . '-' . $max . 'k€/an',
            default => ($max ?? $min) . 'k€/an',
        };
    }

    private static function thousands(int $amount): string
    {
        return (string) (int) round($amount / 1000);
    }
}
