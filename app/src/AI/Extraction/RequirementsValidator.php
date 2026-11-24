<?php

declare(strict_types=1);

namespace App\AI\Extraction;

use App\DTO\Seniority;
use App\DTO\ContractType;
use App\Profile\Model\SalaryPeriod;
use App\Profile\Model\RemotePreference;

/**
 * Valide la réponse du LLM et en fait des `JobRequirements` fiables.
 *
 * La réponse du modèle est une donnée non fiable, au même titre que l'annonce : elle est lue
 * champ par champ, bornée, nettoyée, puis **ancrée** dans le texte de l'annonce — une compétence,
 * une langue, un contrat, un montant ou un lieu que l'annonce ne contient pas est écarté (avec un
 * avertissement) plutôt que conservé. Les clés inconnues sont ignorées. Une valeur invalide devient
 * « inconnue », jamais une valeur par défaut optimiste.
 */
final readonly class RequirementsValidator
{
    private const int MAX_SKILLS = 30;
    private const int MAX_SKILL_LENGTH = 60;
    private const int MAX_LANGUAGES = 6;
    private const int MAX_LOCATIONS = 10;
    private const int MAX_LOCATION_LENGTH = 80;
    private const int MAX_YEARS = 40;
    private const int MAX_AMOUNT = 10_000_000;
    private const int MAX_WARNINGS = 12;

    private const array KNOWN_KEYS = [
        'required_skills', 'nice_skills', 'mentioned_skills', 'seniority', 'min_years_experience',
        'languages', 'remote_mode', 'contract_types', 'salary', 'locations',
    ];

    /** Mots de l'annonce qui justifient un mode de travail (sans accent, voir `TextMatcher`). */
    private const array REMOTE_KEYWORDS = [
        'remote' => ['remote', 'teletravail', 'a distance', 'home office', 'work from home', 'anywhere'],
        'hybrid' => ['hybrid', 'hybride', 'teletravail partiel', 'jours de teletravail', 'jours de remote', 'days remote', 'days a week'],
        'onsite' => ['on-site', 'onsite', 'on site', 'sur site', 'presentiel', 'au bureau', 'in-office', 'in office'],
    ];

    /** Mots de l'annonce qui justifient un type de contrat. */
    private const array CONTRACT_KEYWORDS = [
        'freelance' => ['freelance', 'independant', 'mission', 'tjm', 'contractor', 'portage', 'free-lance'],
        'cdi' => ['cdi', 'indeterminee', 'permanent', 'indefinite'],
        'cdd' => ['cdd', 'duree determinee', 'fixed-term', 'fixed term', 'temporary', 'interim'],
        'alternance' => ['alternance', 'apprentissage', 'apprenti', 'work-study', 'apprenticeship', 'contrat pro'],
        'stage' => ['stage', 'stagiaire', 'internship', 'intern '],
    ];

    private const array CURRENCY_SYMBOLS = ['€' => 'EUR', '$' => 'USD', '£' => 'GBP'];

    /**
     * @param array<array-key, mixed> $data Réponse du LLM, déjà décodée du JSON
     * @param string $sourceText Texte de l'annonce effectivement envoyé au LLM
     * @throws InvalidExtractionException si la réponse ne contient aucune clé du schéma
     */
    public function validate(array $data, string $sourceText): JobRequirements
    {
        if (array_intersect_key($data, array_flip(self::KNOWN_KEYS)) === []) {
            throw new InvalidExtractionException('La réponse ne contient aucun champ du schéma d\'extraction.');
        }

        // Ancien format (`stack`, `contract_type`…) sans aucune des listes de compétences du schéma : la
        // réponse serait silencieusement amputée de ses compétences, mieux vaut passer au repli.
        if (isset($data['stack']) && !isset($data['required_skills']) && !isset($data['nice_skills']) && !isset($data['mentioned_skills'])) {
            throw new InvalidExtractionException('La réponse suit l\'ancien format (« stack ») au lieu du schéma d\'extraction.');
        }

        $matcher = new TextMatcher($sourceText);
        $warnings = [];

        $seen = [];
        $required = $this->skills($data['required_skills'] ?? null, 'required_skills', $matcher, $seen, $warnings);
        $nice = $this->skills($data['nice_skills'] ?? null, 'nice_skills', $matcher, $seen, $warnings);
        $mentioned = $this->skills($data['mentioned_skills'] ?? null, 'mentioned_skills', $matcher, $seen, $warnings);

        $unknown = array_diff(array_map(strval(...), array_keys($data)), self::KNOWN_KEYS);
        if ($unknown !== []) {
            $warnings[] = sprintf('Clés ignorées : %s.', implode(', ', array_map($this->label(...), array_slice($unknown, 0, 5))));
        }

        return new JobRequirements(
            requiredSkills: $required,
            niceSkills: $nice,
            mentionedSkills: $mentioned,
            seniority: $this->seniority($data['seniority'] ?? null),
            minYearsExperience: $this->years($data['min_years_experience'] ?? null, $warnings),
            languages: $this->languages($data['languages'] ?? null, $matcher, $warnings),
            remoteMode: $this->remoteMode($data['remote_mode'] ?? null, $matcher, $warnings),
            contractTypes: $this->contracts($data['contract_types'] ?? null, $matcher, $warnings),
            salary: $this->salary($data['salary'] ?? null, $matcher, $warnings),
            locations: $this->locations($data['locations'] ?? null, $matcher, $warnings),
            source: ExtractionSource::Llm,
            warnings: array_slice($warnings, 0, self::MAX_WARNINGS),
        );
    }

    /**
     * @param array<string, true> $seen Compétences déjà retenues (une compétence n'est qu'une fois, la plus exigeante d'abord)
     * @param list<string> $warnings
     * @return list<string>
     */
    private function skills(mixed $value, string $field, TextMatcher $matcher, array &$seen, array &$warnings): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            $warnings[] = sprintf('Champ « %s » ignoré : liste attendue.', $field);

            return [];
        }

        $skills = [];
        foreach ($value as $item) {
            $skill = $this->text($item, self::MAX_SKILL_LENGTH);
            if ($skill === null) {
                continue;
            }

            $key = TextMatcher::fold($skill);
            if (isset($seen[$key])) {
                continue;
            }

            if (!$matcher->contains($skill)) {
                $warnings[] = sprintf('Compétence « %s » absente de l\'annonce, écartée.', $skill);

                continue;
            }

            if (count($skills) >= self::MAX_SKILLS) {
                $warnings[] = sprintf('Champ « %s » tronqué à %d compétences.', $field, self::MAX_SKILLS);

                break;
            }

            $seen[$key] = true;
            $skills[] = $skill;
        }

        return $skills;
    }

    private function seniority(mixed $value): Seniority
    {
        return is_string($value) ? (Seniority::tryFrom(strtolower(trim($value))) ?? Seniority::Unknown) : Seniority::Unknown;
    }

    /** @param list<string> $warnings */
    private function years(mixed $value, array &$warnings): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $years = match (true) {
            is_int($value) => $value,
            is_float($value) && floor($value) === $value => (int) $value,
            is_string($value) && preg_match('/^\d{1,2}$/', trim($value)) === 1 => (int) trim($value),
            default => null,
        };

        if ($years === null || $years < 0 || $years > self::MAX_YEARS) {
            $warnings[] = 'Années d\'expérience ignorées : entier entre 0 et 40 attendu.';

            return null;
        }

        return $years;
    }

    /**
     * @param list<string> $warnings
     * @return list<RequiredLanguage>
     */
    private function languages(mixed $value, TextMatcher $matcher, array &$warnings): array
    {
        if (!is_array($value)) {
            return [];
        }

        $languages = [];
        $seen = [];
        foreach ($value as $item) {
            // Un nom seul (sans indication d'exigence) est une langue citée mais non exigée.
            $item = is_string($item) ? ['name' => $item, 'required' => false] : $item;
            if (!is_array($item)) {
                continue;
            }

            $name = $this->text($item['name'] ?? null, 40);
            if ($name === null || isset($seen[TextMatcher::fold($name)])) {
                continue;
            }

            if (!$matcher->containsAny(LanguageGroups::names($name))) {
                $warnings[] = sprintf('Langue « %s » absente de l\'annonce, écartée.', $name);

                continue;
            }

            if (count($languages) >= self::MAX_LANGUAGES) {
                break;
            }

            $seen[TextMatcher::fold($name)] = true;
            $languages[] = new RequiredLanguage($name, $this->text($item['level'] ?? null, 40), ($item['required'] ?? false) === true);
        }

        return $languages;
    }

    /** @param list<string> $warnings */
    private function remoteMode(mixed $value, TextMatcher $matcher, array &$warnings): ?RemotePreference
    {
        $mode = is_string($value) ? RemotePreference::tryFrom(strtolower(trim($value))) : null;
        if ($mode === null) {
            return null;
        }

        if (!$matcher->containsAny(self::REMOTE_KEYWORDS[$mode->value])) {
            $warnings[] = sprintf('Mode de travail « %s » non justifié par l\'annonce, écarté.', $mode->value);

            return null;
        }

        return $mode;
    }

    /**
     * @param list<string> $warnings
     * @return list<ContractType>
     */
    private function contracts(mixed $value, TextMatcher $matcher, array &$warnings): array
    {
        if (!is_array($value)) {
            return [];
        }

        $types = [];
        foreach ($value as $item) {
            $type = is_string($item) ? ContractType::tryFrom(strtolower(trim($item))) : null;
            if ($type === null || $type === ContractType::Unknown || in_array($type, $types, true)) {
                continue;
            }

            if (!$matcher->containsAny(self::CONTRACT_KEYWORDS[$type->value])) {
                $warnings[] = sprintf('Contrat « %s » non mentionné par l\'annonce, écarté.', $type->value);

                continue;
            }

            $types[] = $type;
        }

        return $types;
    }

    /** @param list<string> $warnings */
    private function salary(mixed $value, TextMatcher $matcher, array &$warnings): ?SalaryRange
    {
        if (!is_array($value)) {
            return null;
        }

        $min = $this->amount($value['min'] ?? null);
        $max = $this->amount($value['max'] ?? null);
        if ($min === null && $max === null) {
            return null;
        }

        $period = is_string($value['period'] ?? null) ? SalaryPeriod::tryFrom(strtolower(trim($value['period']))) : null;
        if ($period === null) {
            $warnings[] = 'Rémunération écartée : période non prise en charge (journalière ou annuelle attendue).';

            return null;
        }

        // Aucune devise n'est supposée : sans devise explicite, le montant est inexploitable.
        $currency = $this->currency($value['currency'] ?? null);
        if ($currency === null) {
            $warnings[] = 'Rémunération écartée : devise absente ou invalide.';

            return null;
        }

        if ($min !== null && $max !== null && $min > $max) {
            $warnings[] = 'Rémunération écartée : minimum supérieur au maximum.';

            return null;
        }

        foreach ([$min, $max] as $amount) {
            if ($amount !== null && !$matcher->containsAmount($amount)) {
                $warnings[] = 'Rémunération écartée : montant absent de l\'annonce.';

                return null;
            }
        }

        return new SalaryRange($min, $max, $currency, $period);
    }

    private function amount(mixed $value): ?int
    {
        $amount = match (true) {
            is_int($value) => $value,
            is_float($value) && floor($value) === $value => (int) $value,
            is_string($value) && preg_match('/^\d{1,8}$/', trim($value)) === 1 => (int) trim($value),
            default => null,
        };

        return $amount !== null && $amount > 0 && $amount <= self::MAX_AMOUNT ? $amount : null;
    }

    private function currency(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $value = self::CURRENCY_SYMBOLS[$value] ?? strtoupper($value);

        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    private function locations(mixed $value, TextMatcher $matcher, array &$warnings): array
    {
        if (!is_array($value)) {
            return [];
        }

        $locations = [];
        foreach ($value as $item) {
            $location = $this->text($item, self::MAX_LOCATION_LENGTH);
            if ($location === null || in_array($location, $locations, true)) {
                continue;
            }

            if (!$matcher->contains($location)) {
                $warnings[] = sprintf('Lieu « %s » absent de l\'annonce, écarté.', $location);

                continue;
            }

            if (count($locations) >= self::MAX_LOCATIONS) {
                break;
            }

            $locations[] = $location;
        }

        return $locations;
    }

    /** Texte court nettoyé : sans balise ni caractère de contrôle, espaces normalisés ; `null` si vide ou trop long. */
    private function text(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = strip_tags($value);
        $text = (string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{2060}\x{FEFF}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' || mb_strlen($text) > $maxLength ? null : $text;
    }

    private function label(string $key): string
    {
        return mb_substr((string) preg_replace('/[^\p{L}\p{N}_ -]/u', '', $key), 0, 30);
    }
}
