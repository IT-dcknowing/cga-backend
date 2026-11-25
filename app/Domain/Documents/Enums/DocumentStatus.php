<?php

namespace App\Domain\Documents\Enums;

use App\Support\Enums\HasLabel;

/**
 * État d'un document produit (02-TRD §8.2) : en production, valide, annulé, en échec.
 */
enum DocumentStatus: string
{
    use HasLabel;

    case Generating = 'generating';
    case Valid = 'valid';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
