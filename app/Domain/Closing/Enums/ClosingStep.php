<?php

namespace App\Domain\Closing\Enums;

use App\Support\Enums\HasLabel;

/**
 * Étapes de la campagne de clôture (F-OBL-08), dans l'ordre du calendrier : collecte des pièces,
 * régularisation, finalisation du SMT ou des états financiers, dépôt légal.
 */
enum ClosingStep: string
{
    use HasLabel;

    case Pieces = 'pieces';
    case Regularisation = 'regularisation';
    case Finalisation = 'finalisation';
    case Filing = 'filing';

    /** Colonne de la date de l'étape sur le suivi d'un dossier. */
    public function column(): string
    {
        return "step_{$this->value}_at";
    }
}
