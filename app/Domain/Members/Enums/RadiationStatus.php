<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Radiation en deux temps (RG-ADH-17) : demandée, validée, annulée.
 */
enum RadiationStatus: string
{
    use HasLabel;

    case Requested = 'requested';
    case Validated = 'validated';
    case Cancelled = 'cancelled';
}
