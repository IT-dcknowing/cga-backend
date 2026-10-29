<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Origine d'une obligation attribuée à un adhérent (F-OBL-02) : proposée par la plateforme (RG-OBL-02)
 * ou ajoutée par le CGA.
 */
enum MemberObligationSource: string
{
    use HasLabel;

    case Suggested = 'suggested';
    case Manual = 'manual';
}
