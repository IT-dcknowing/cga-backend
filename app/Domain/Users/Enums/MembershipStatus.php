<?php

namespace App\Domain\Users\Enums;

use App\Support\Enums\HasLabel;

/**
 * État d'un rattachement (05-BACKEND-SCHEMA §2). « read_only » : adhérent radié, consultation seule (RG-ADH-12).
 */
enum MembershipStatus: string
{
    use HasLabel;

    case Invited = 'invited';
    case Active = 'active';
    case ReadOnly = 'read_only';
    case Disabled = 'disabled';
}
