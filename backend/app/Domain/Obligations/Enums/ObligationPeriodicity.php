<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Périodicité d'une obligation du catalogue (F-OBL-01).
 */
enum ObligationPeriodicity: string
{
    use HasLabel;

    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';
    case OneOff = 'one_off';
    case OnEvent = 'on_event';
}
