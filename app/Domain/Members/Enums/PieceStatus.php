<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * État d'une pièce (RG-PJ-01).
 */
enum PieceStatus: string
{
    use HasLabel;

    case Expected = 'expected';
    case Received = 'received';
    case Expired = 'expired';
    case NotApplicable = 'not_applicable';
}
