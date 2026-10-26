<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * État d'un dossier (F-ADH-01) : brouillon, actif, radiation en cours, radié, clos (dossier Cabinet).
 */
enum MemberStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Active = 'active';
    case RadiationPending = 'radiation_pending';
    case Radiated = 'radiated';
    case Closed = 'closed';
}
