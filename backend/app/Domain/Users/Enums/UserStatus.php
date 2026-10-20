<?php

namespace App\Domain\Users\Enums;

use App\Support\Enums\HasLabel;

enum UserStatus: string
{
    use HasLabel;

    case Invited = 'invited';
    case Active = 'active';
    case Disabled = 'disabled';
    case Deleted = 'deleted';
}
