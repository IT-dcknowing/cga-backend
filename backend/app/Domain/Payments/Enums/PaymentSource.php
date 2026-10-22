<?php

namespace App\Domain\Payments\Enums;

use App\Support\Enums\HasLabel;

/**
 * Origine d'un paiement : saisi par le CGA, déclaré par l'adhérent (preuve jointe), importé.
 */
enum PaymentSource: string
{
    use HasLabel;

    case CgaEntry = 'cga_entry';
    case MemberDeclaration = 'member_declaration';
    case Import = 'import';
}
