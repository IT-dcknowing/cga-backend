<?php

namespace App\Domain\Payments\Enums;

use App\Support\Enums\HasLabel;

/**
 * État d'un paiement (F-PAY-03, 04) : à confirmer, confirmé, rejeté.
 */
enum PaymentStatus: string
{
    use HasLabel;

    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
