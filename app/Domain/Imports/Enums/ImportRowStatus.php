<?php

namespace App\Domain\Imports\Enums;

use App\Support\Enums\HasLabel;

/**
 * Sort d'une ligne importée (RG-IMP-02) : importée, importée à compléter (champs manquants listés),
 * rejetée avec motif.
 */
enum ImportRowStatus: string
{
    use HasLabel;

    case Imported = 'imported';
    case Draft = 'draft';
    case Rejected = 'rejected';
}
