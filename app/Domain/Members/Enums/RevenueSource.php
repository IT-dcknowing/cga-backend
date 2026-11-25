<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Origine d'un chiffre d'affaires (RG-DATA-06).
 */
enum RevenueSource: string
{
    use HasLabel;

    case Entry = 'entry';
    case Import = 'import';
    case Report = 'report';
    case ComptaFlow = 'compta_flow';
}
