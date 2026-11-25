<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * État d'une obligation attribuée (F-OBL-02) : proposée en attente de confirmation, active, retirée.
 */
enum MemberObligationStatus: string
{
    use HasLabel;

    case Suggested = 'suggested';
    case Active = 'active';
    case Removed = 'removed';
}
