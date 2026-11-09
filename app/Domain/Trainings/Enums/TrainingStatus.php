<?php

namespace App\Domain\Trainings\Enums;

use App\Support\Enums\HasLabel;

/**
 * Statut d'une formation : brouillon, publiée (inscriptions ouvertes jusqu'au début), annulée (motif), terminée (session clôturée, attestations produites).
 */
enum TrainingStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
}
