<?php

namespace App\Domain\Payments\Enums;

use App\Support\Enums\HasLabel;

/**
 * Moyen de paiement (F-PAY-03).
 */
enum PaymentMethod: string
{
    use HasLabel;

    case Cash = 'cash';
    case Cheque = 'cheque';
    case Transfer = 'transfer';
    case MobileMoney = 'mobile_money';
    case Other = 'other';
}
