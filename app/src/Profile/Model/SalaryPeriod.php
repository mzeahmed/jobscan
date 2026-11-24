<?php

declare(strict_types=1);

namespace App\Profile\Model;

enum SalaryPeriod: string
{
    /** Taux journalier (freelance). */
    case Daily = 'daily';
    /** Salaire annuel brut (salarié). */
    case Annual = 'annual';
}
