<?php

declare(strict_types=1);

namespace App\Profile\Model;

use App\DTO\ContractType;

/**
 * Prétention salariale pour un type de contrat : un taux journalier pour le freelance, un salaire
 * annuel brut pour un CDI. Au moins une borne est renseignée.
 */
final readonly class SalaryExpectation
{
    public function __construct(
        public ?ContractType $contract,
        public SalaryPeriod $period,
        public ?int $min,
        public ?int $max,
        public string $currency,
    ) {
    }
}
