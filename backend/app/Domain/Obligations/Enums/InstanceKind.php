<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Nature d'une échéance (F-OBL-03) : déclaration, paiement, diligence.
 */
enum InstanceKind: string
{
    use HasLabel;

    case Declaration = 'declaration';
    case Payment = 'payment';
    case Diligence = 'diligence';
}
