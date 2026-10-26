<?php

namespace App\Domain\Referentials\Enums;

use App\Support\Enums\HasLabel;

/**
 * Données de référence provisoires, remplaçables par les données réelles (RG-DATA-01 à 05).
 */
enum DataStatus: string
{
    use HasLabel;

    case Provisional = 'provisional';
    case Validated = 'validated';
}
