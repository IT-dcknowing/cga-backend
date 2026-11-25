<?php

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Models\MemberPayment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Paiement déclaré par l'adhérent rejeté (F-PAY-03), émis dans la transaction du rejet : l'adhérent
 * en est notifié avec le motif (Lot 6).
 */
class PaymentRejected
{
    use Dispatchable;

    public function __construct(public readonly MemberPayment $payment) {}
}
