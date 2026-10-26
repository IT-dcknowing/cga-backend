<?php

namespace App\Domain\Referentials\ReferenceData;

use App\Support\Enums\HasLabel;

/**
 * Import d'un jeu de référence (RG-DATA-01) : « mettre à jour » ajoute et modifie ; « remplacer »
 * fait de même et retire ce qui n'est pas dans le fichier.
 */
enum ImportMode: string
{
    use HasLabel;

    case Update = 'update';
    case Replace = 'replace';
}
