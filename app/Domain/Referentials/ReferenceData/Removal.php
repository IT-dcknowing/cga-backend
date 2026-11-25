<?php

namespace App\Domain\Referentials\ReferenceData;

use App\Support\Enums\HasLabel;

/**
 * Sort, en mode « remplacer », d'une valeur absente du fichier (RG-DATA-05) : supprimée si rien ne
 * l'utilise, désactivée si elle est utilisée, gardée si elle est utilisée et ne se désactive pas.
 */
enum Removal: string
{
    use HasLabel;

    case Delete = 'delete';
    case Deactivate = 'deactivate';
    case Keep = 'keep';
}
