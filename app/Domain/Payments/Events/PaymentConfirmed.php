<?php

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Models\MemberPayment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Paiement confirmé (F-PAY-02, 03), émis dans la transaction de la confirmation : la chaîne
 * documentaire y produit le reçu numéroté (étape 3.5).
 */
class PaymentConfirmed
{
    use Dispatchable;

    public function __construct(public readonly MemberPayment $payment) {}
}
