<?php

namespace App\Domain\Concerns\Enums;

use App\Support\Enums\HasLabel;

/**
 * Statut d'une préoccupation (F-PRE-02) : Ouverte → En examen → Traitée ou Rejetée (motif) ; le
 * demandeur rouvre une demande traitée ou rejetée (retour à « Ouverte »).
 */
enum ConcernStatus: string
{
    use HasLabel;

    case Open = 'open';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    /** Traitée ou rejetée : plus aucun message tant que le demandeur ne l'a pas rouverte. */
    public function isClosed(): bool
    {
        return $this === self::Resolved || $this === self::Rejected;
    }

    /**
     * Statuts que choisit celui qui traite la demande, depuis celui-ci.
     *
     * @return list<self>
     */
    public function handlerTargets(): array
    {
        return match ($this) {
            self::Open => [self::InReview, self::Resolved, self::Rejected],
            self::InReview => [self::Resolved, self::Rejected],
            self::Resolved, self::Rejected => [],
        };
    }
}
