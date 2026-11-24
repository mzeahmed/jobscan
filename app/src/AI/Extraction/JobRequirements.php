<?php

declare(strict_types=1);

namespace App\AI\Extraction;

use App\DTO\Seniority;
use App\DTO\ContractType;
use App\Profile\Model\SalaryPeriod;
use App\Profile\Model\RemotePreference;

/**
 * Exigences et caractéristiques d'une annonce, extraites **indépendamment de tout profil candidat**.
 *
 * Une information absente de l'annonce reste inconnue (`null`, liste vide, `Seniority::Unknown`) :
 * elle ne signifie jamais « non », et le matching devra la traiter comme inconnue. Le schéma est
 * versionné (`SCHEMA_VERSION`) pour permettre la ré-extraction ciblée des annonces dont le schéma a changé.
 */
final readonly class JobRequirements
{
    /** À incrémenter à chaque changement de schéma (champs, sémantique) : voir `app:jobs:analyze --outdated`. */
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $requiredSkills Compétences explicitement exigées
     * @param list<string> $niceSkills Compétences explicitement appréciées (« un plus », « souhaité »)
     * @param list<string> $mentionedSkills Technologies citées sans niveau d'exigence clair
     * @param int|null $minYearsExperience Années d'expérience demandées, si l'annonce en donne
     * @param list<RequiredLanguage> $languages
     * @param RemotePreference|null $remoteMode Mode de travail annoncé ; null si l'annonce ne le dit pas
     * @param list<ContractType> $contractTypes Contrats annoncés (jamais `Unknown`)
     * @param list<string> $locations Lieux cités par l'annonce
     * @param list<string> $warnings Points signalés à l'extraction (éléments écartés, annonce suspecte)
     */
    public function __construct(
        public array $requiredSkills = [],
        public array $niceSkills = [],
        public array $mentionedSkills = [],
        public Seniority $seniority = Seniority::Unknown,
        public ?int $minYearsExperience = null,
        public array $languages = [],
        public ?RemotePreference $remoteMode = null,
        public array $contractTypes = [],
        public ?SalaryRange $salary = null,
        public array $locations = [],
        public ExtractionSource $source = ExtractionSource::Llm,
        public array $warnings = [],
    ) {
    }

    /**
     * Toutes les technologies citées, exigées d'abord.
     *
     * @return list<string>
     */
    public function allSkills(): array
    {
        return [...$this->requiredSkills, ...$this->niceSkills, ...$this->mentionedSkills];
    }

    /** @param list<string> $warnings */
    public function withWarnings(array $warnings): self
    {
        return new self(
            $this->requiredSkills,
            $this->niceSkills,
            $this->mentionedSkills,
            $this->seniority,
            $this->minYearsExperience,
            $this->languages,
            $this->remoteMode,
            $this->contractTypes,
            $this->salary,
            $this->locations,
            $this->source,
            [...$this->warnings, ...$warnings],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source' => $this->source->value,
            'required_skills' => $this->requiredSkills,
            'nice_skills' => $this->niceSkills,
            'mentioned_skills' => $this->mentionedSkills,
            'seniority' => $this->seniority->value,
            'min_years_experience' => $this->minYearsExperience,
            'languages' => array_map(static fn (RequiredLanguage $language): array => $language->toArray(), $this->languages),
            'remote_mode' => $this->remoteMode?->value,
            'contract_types' => array_map(static fn (ContractType $type): string => $type->value, $this->contractTypes),
            'salary' => $this->salary?->toArray(),
            'locations' => $this->locations,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Relit des exigences stockées. Tolérant : un champ invalide est ignoré plutôt que de faire
     * échouer la lecture. Retourne `null` pour une version de schéma inconnue ou plus récente.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $version = $data['schema_version'] ?? null;
        if (!is_int($version) || $version < 1 || $version > self::SCHEMA_VERSION) {
            return null;
        }

        $salary = is_array($data['salary'] ?? null) ? $data['salary'] : null;
        $period = $salary === null ? null : SalaryPeriod::tryFrom((string) ($salary['period'] ?? ''));

        return new self(
            requiredSkills: self::strings($data['required_skills'] ?? null),
            niceSkills: self::strings($data['nice_skills'] ?? null),
            mentionedSkills: self::strings($data['mentioned_skills'] ?? null),
            seniority: Seniority::tryFrom((string) ($data['seniority'] ?? '')) ?? Seniority::Unknown,
            minYearsExperience: is_int($data['min_years_experience'] ?? null) ? $data['min_years_experience'] : null,
            languages: self::languages($data['languages'] ?? null),
            remoteMode: RemotePreference::tryFrom((string) ($data['remote_mode'] ?? '')),
            contractTypes: self::contracts($data['contract_types'] ?? null),
            salary: $salary !== null && $period !== null
                ? new SalaryRange(
                    is_int($salary['min'] ?? null) ? $salary['min'] : null,
                    is_int($salary['max'] ?? null) ? $salary['max'] : null,
                    is_string($salary['currency'] ?? null) ? $salary['currency'] : 'EUR',
                    $period,
                )
                : null,
            locations: self::strings($data['locations'] ?? null),
            source: ExtractionSource::tryFrom((string) ($data['source'] ?? '')) ?? ExtractionSource::Llm,
            warnings: self::strings($data['warnings'] ?? null),
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /** @return list<RequiredLanguage> */
    private static function languages(mixed $value): array
    {
        $languages = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item) && is_string($item['name'] ?? null)) {
                $languages[] = new RequiredLanguage(
                    $item['name'],
                    is_string($item['level'] ?? null) ? $item['level'] : null,
                    ($item['required'] ?? true) !== false,
                );
            }
        }

        return $languages;
    }

    /** @return list<ContractType> */
    private static function contracts(mixed $value): array
    {
        $types = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $type = is_string($item) ? ContractType::tryFrom($item) : null;
            if ($type !== null && $type !== ContractType::Unknown) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
