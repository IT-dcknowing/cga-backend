<?php

namespace App\Domain\Trainings\Enums;

use App\Support\Enums\HasLabel;

/**
 * Statut d'une inscription : en attente de validation (RG-FOR-05), inscrite, en liste d'attente (RG-FOR-03), annulée (désinscription, RG-FOR-04), refusée par l'organisateur.
 */
enum RegistrationStatus: string
{
    use HasLabel;

    case PendingApproval = 'pending_approval';
    case Registered = 'registered';
    case Waitlisted = 'waitlisted';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    /** Inscription en cours : ni annulée ni refusée. */
    public function isActive(): bool
    {
        return $this !== self::Cancelled && $this !== self::Rejected;
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::PendingApproval, self::Registered, self::Waitlisted];
    }
}
