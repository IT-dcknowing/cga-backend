<?php

namespace App\Domain\Organizations\Enums;

use App\Support\Enums\HasLabel;

enum OrganizationStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
}
