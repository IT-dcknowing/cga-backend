<?php

namespace App\Domain\Referentials\Enums;

use App\Support\Enums\HasLabel;

/**
 * Statut d'un secteur d'activité (RG-REF-01) : validé (national), proposé par un CGA, rejeté ou
 * fusionné dans un autre secteur.
 */
enum SectorStatus: string
{
    use HasLabel;

    case Validated = 'validated';
    case Proposed = 'proposed';
    case Rejected = 'rejected';
    case Merged = 'merged';
}
