<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Portefeuille d'un dossier (RG-PTF-01, 02) : CGA (contrat d'adhésion) ou Cabinet comptable.
 */
enum Portfolio: string
{
    use HasLabel;

    case Cga = 'cga';
    case Cabinet = 'cabinet';
}
