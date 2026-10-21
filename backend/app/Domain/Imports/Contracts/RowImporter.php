<?php

namespace App\Domain\Imports\Contracts;

use App\Domain\Imports\Data\RowOutcome;

/**
 * Import d'un type de fichier (M17) : colonnes du modèle Excel et traitement d'une ligne, chacune
 * dans sa propre transaction.
 */
interface RowImporter
{
    /**
     * Colonnes du modèle, dans l'ordre : clé => [libellé de l'en-tête, aide, autres en-têtes admis].
     *
     * @return array<string, array{label: string, help: string, aliases: list<string>}>
     */
    public function columns(): array;

    /**
     * Colonnes sans lesquelles le fichier n'est pas lu.
     *
     * @return list<string>
     */
    public function requiredColumns(): array;

    /**
     * Colonne qui nomme la ligne dans le rapport (dénomination, nom de l'entreprise…).
     */
    public function labelColumn(): string;

    /**
     * Nom de base des fichiers de l'import : modele-{nom}.xlsx, rapport-{nom}-{date}.xlsx.
     */
    public function fileName(): string;

    /**
     * @param  array<string, mixed>  $values  valeurs de la ligne par clé de colonne (null si vide)
     */
    public function import(array $values): RowOutcome;
}
