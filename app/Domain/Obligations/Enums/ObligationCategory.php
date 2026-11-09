<?php

namespace App\Domain\Obligations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Bloc d'une obligation (F-OBL-04) : impôts et taxes, CNPS, CMU, autres administrations.
 */
enum ObligationCategory: string
{
    use HasLabel;

    case Tax = 'tax';
    case Cnps = 'cnps';
    case Cmu = 'cmu';
    case Other = 'other';
}
