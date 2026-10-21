<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Initiateur d'une radiation (F-ADH-06, CR-06).
 */
enum RadiationInitiator: string
{
    use HasLabel;

    case Cga = 'cga';
    case Member = 'member';
    case SpCga = 'sp_cga';
}
