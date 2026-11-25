<?php

namespace App\Domain\Payments\Enums;

use App\Support\Enums\HasLabel;

/**
 * Nature d'une facture émise (F-PAY-05) : facture, ou avoir qui annule une facture certifiée (RG-FNE-01).
 */
enum InvoiceType: string
{
    use HasLabel;

    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
}
