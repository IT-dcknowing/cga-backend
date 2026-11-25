<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Pièce justificative attendue (F-PJ-01).
 */
enum PieceType: string
{
    use HasLabel;

    case Rccm = 'rccm';
    case Dfe = 'dfe';
    case IdDocument = 'id_document';
    case Statutes = 'statutes';
    case LocationPlan = 'location_plan';
    case SignedCgaContract = 'signed_cga_contract';
}
