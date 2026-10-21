<?php

namespace App\Domain\Imports\Contracts;

use App\Domain\Imports\Data\RowOutcome;

/**
 * Import d'un type de fichier (M17) : disposition du modèle Excel et traitement d'une ligne, chacune
 * dans sa propre transaction.
 */
interface RowImporter extends SpreadsheetLayout
{
    /**
     * @param  array<string, mixed>  $values  valeurs de la ligne par clé de colonne (null si vide)
     */
    public function import(array $values): RowOutcome;
}
