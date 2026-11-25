<?php

namespace App\Domain\Documents\Contracts;

/**
 * Contrôle de structure d'un modèle déposé (F-ADM-02, RG-DATA-03) : ce que le remplissage du type attend
 * du fichier, au-delà de ses variables (feuilles, blocs, lignes). Déclaré par le type dans
 * config/documents.php (« structure »).
 */
interface ChecksTemplateStructure
{
    /**
     * Écarts constatés ; une liste vide si le fichier convient.
     *
     * @return list<string>
     */
    public function problems(string $path): array;
}
