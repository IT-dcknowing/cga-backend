<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Nature d'une obligation du catalogue (F-OBL-01) : déclaration, paiement, déclaration puis paiement
 * (RG-OBL-05), diligence.
 */
enum ObligationKind: string
{
    use HasLabel;

    case Declaration = 'declaration';
    case Payment = 'payment';
    case DeclarationPayment = 'declaration_payment';
    case Diligence = 'diligence';
}
