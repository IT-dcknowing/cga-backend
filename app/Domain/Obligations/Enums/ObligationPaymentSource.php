<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Origine d'un paiement d'échéance : saisi par le CGA, déclaré par l'adhérent (RG-OBL-09), importé,
 * Compta Flow (confirmé d'office et verrouillé, RG-DATA-06).
 */
enum ObligationPaymentSource: string
{
    use HasLabel;

    case CgaEntry = 'cga_entry';
    case MemberDeclaration = 'member_declaration';
    case Import = 'import';
    case ComptaFlow = 'compta_flow';
}
