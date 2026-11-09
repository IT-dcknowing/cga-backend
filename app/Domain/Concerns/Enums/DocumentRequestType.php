<?php

namespace App\Domain\Concerns\Enums;

use App\Support\Enums\HasLabel;

/**
 * Document demandé par l'adhérent (F-DOC-06, 03-APPFLOW §5 « Demander un document »).
 */
enum DocumentRequestType: string
{
    use HasLabel;

    case Attestation = 'attestation';
    case FinancialStatements = 'financial_statements';
    case Other = 'other';
}
