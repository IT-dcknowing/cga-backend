<?php

namespace App\Domain\Organizations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Agrément du CGA par l'administration fiscale.
 */
enum AgreementStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';
}
