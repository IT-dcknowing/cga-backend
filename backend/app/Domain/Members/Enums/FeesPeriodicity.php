<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Périodicité des honoraires (F-PAY-01).
 */
enum FeesPeriodicity: string
{
    use HasLabel;

    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
}
