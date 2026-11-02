<?php

namespace App\Domain\Closing\Enums;

use App\Support\Enums\HasLabel;

/**
 * Document de clôture d'un dossier (F-OBL-08) : SMT ou états financiers.
 */
enum ClosingDeliverable: string
{
    use HasLabel;

    case Smt = 'smt';
    case FinancialStatements = 'financial_statements';
}
