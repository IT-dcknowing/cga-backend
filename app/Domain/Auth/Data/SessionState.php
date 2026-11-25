<?php

namespace App\Domain\Auth\Data;

use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * État de la session renvoyé par GET /api/v1/me (02-TRD §6.2) : l'utilisateur, ses rattachements
 * utilisables, l'espace actif enregistré en session et les droits effectifs de cet espace.
 */
final readonly class SessionState
{
    /**
     * @param  Collection<int, Membership>  $memberships
     * @param  list<string>  $permissions  droits accordés dans l'espace actif (aucun sans espace)
     */
    public function __construct(
        public User $user,
        public Collection $memberships,
        public ?string $activeMembershipId,
        public array $permissions,
    ) {}
}
