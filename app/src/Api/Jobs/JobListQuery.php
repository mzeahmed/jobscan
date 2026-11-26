<?php

declare(strict_types=1);

namespace App\Api\Jobs;

use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\JobListingFilters;
use App\Api\Shared\InvalidInputException;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Paramètres de `GET /api/v1/jobs`, validés strictement : une valeur inconnue est refusée (422), pas ignorée en silence
 * comme dans l'interface Twig, pour que le front n'affiche pas une liste qui ne correspond pas à ce qu'il a demandé.
 */
final readonly class JobListQuery
{
    // `InputBag::get()` refuse déjà un tableau à la place d'un scalaire (400) : les valeurs lues ici sont des chaînes.

    public const int DEFAULT_PER_PAGE = 20;
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        public JobListingFilters $filters,
        public int $page,
        public int $perPage,
    ) {
    }

    /**
     * @param InputBag<string> $query
     *
     * @throws InvalidInputException
     */
    public static function fromQuery(InputBag $query): self
    {
        $errors = [];
        $text = static function (string $name, int $max = 100) use ($query, &$errors): ?string {
            $value = $query->get($name);
            if ($value === null) {
                return null;
            }

            if (mb_strlen(trim($value)) > $max) {
                $errors[$name] = 'Valeur invalide.';

                return null;
            }

            return trim($value) === '' ? null : trim($value);
        };
        $choice = static function (string $name, array $allowed) use ($query, &$errors): ?string {
            $value = $query->get($name);
            if ($value === null || $value === '') {
                return null;
            }

            if (!\in_array($value, $allowed, true)) {
                $errors[$name] = 'Valeur attendue : ' . implode(', ', $allowed) . '.';

                return null;
            }

            return $value;
        };
        $integer = static function (string $name, int $min, int $max, ?int $default) use ($query, &$errors): ?int {
            $value = $query->get($name);
            if ($value === null || $value === '') {
                return $default;
            }

            $number = filter_var($value, FILTER_VALIDATE_INT);
            if (!\is_int($number) || $number < $min || $number > $max) {
                $errors[$name] = sprintf('Entier attendu entre %d et %d.', $min, $max);

                return $default;
            }

            return $number;
        };
        $yesNo = static fn (string $name): ?bool => match ($choice($name, ['yes', 'no'])) {
            'yes' => true, 'no' => false, default => null,
        };

        $filters = new JobListingFilters(
            location: $text('location'),
            remote: match ($choice('remote', ['remote', 'onsite'])) {
                'remote' => true, 'onsite' => false, default => null,
            },
            contractType: ($value = $choice('contract', array_column(ContractType::cases(), 'value'))) !== null ? ContractType::from($value) : null,
            seniority: ($value = $choice('seniority', array_column(Seniority::cases(), 'value'))) !== null ? Seniority::from($value) : null,
            freelance: $yesNo('freelance'),
            minimumScore: $integer('min_score', 0, 100, null),
            source: $text('source'),
            ageInDays: ($value = $choice('age', ['1', '7', '30'])) !== null ? (int) $value : null,
            notified: $yesNo('notified'),
            sort: $choice('sort', ['date', 'score']) ?? 'date',
            state: $choice('state', ['saved', 'ignored', 'archived', 'all']) ?? 'active',
        );
        $page = $integer('page', 1, 100000, 1) ?? 1;
        $perPage = $integer('per_page', 1, self::MAX_PER_PAGE, self::DEFAULT_PER_PAGE) ?? self::DEFAULT_PER_PAGE;

        if ($errors !== []) {
            throw new InvalidInputException($errors);
        }

        return new self($filters, $page, $perPage);
    }
}
