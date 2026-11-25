<?php

namespace App\Domain\Reports\Enums;

use App\Support\Enums\HasLabel;

/**
 * Mode de transmission du rapport au SP-CGA, faite par le CGA lui-même (F-RPT-04, CR-05).
 */
enum TransmissionMode: string
{
    use HasLabel;

    case Physical = 'physical';
    case Email = 'email';
    case Other = 'other';
}
