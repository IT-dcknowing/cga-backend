<?php

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Models\MemberPayment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Paiement déclaré par l'adhérent (F-PAY-03), émis dans la transaction de la déclaration : le CGA
 * en est averti (Lot 6).
 */
class PaymentDeclared
{
    use Dispatchable;

    public function __construct(public readonly MemberPayment $payment) {}
}
