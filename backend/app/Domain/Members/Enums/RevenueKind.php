<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Chiffre d'affaires d'une année : prévisionnel ou réalisé.
 */
enum RevenueKind: string
{
    use HasLabel;

    case Forecast = 'forecast';
    case Actual = 'actual';
}
