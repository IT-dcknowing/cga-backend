<?php

namespace App\Domain\Dues\Enums;

use App\Support\Enums\HasLabel;

/**
 * Origine d'un paiement de cotisation : déclaré par le CGA avec sa preuve (F-COT-03) ou saisi par le
 * CNCGA (F-COT-04).
 */
enum DuesPaymentSource: string
{
    use HasLabel;

    case CgaDeclaration = 'cga_declaration';
    case CncgaEntry = 'cncga_entry';
}
