<?php

namespace App\Domain\Payments\Enums;

use App\Support\Enums\HasLabel;

/**
 * État d'une somme due (RG-PAY-03, 04) : due, payée en partie ou en totalité, impayée, annulée.
 */
enum ChargeStatus: string
{
    use HasLabel;

    case Due = 'due';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
}
