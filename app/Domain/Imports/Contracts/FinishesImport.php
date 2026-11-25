<?php

namespace App\Domain\Imports\Contracts;

use App\Domain\Imports\Models\ImportBatch;

/**
 * Importateur qui termine le lot une fois toutes les lignes lues, dans une transaction : par exemple
 * la double numérotation d'un portefeuille repris, dans l'ordre chronologique des adhésions
 * (RG-IMP-01).
 */
interface FinishesImport
{
    /**
     * @param  array<string, mixed>  $options  choix faits au dépôt du fichier
     * @return array<string, string> remarque à ajouter au rapport, par identifiant de l'objet créé
     */
    public function finish(ImportBatch $batch, array $options): array;
}
