<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Choix de l'espace de travail (F-AUTH-04) : seul un rattachement utilisable de l'utilisateur peut
 * être choisi (404 sinon, sans dire s'il existe) ; le choix est mémorisé pour la connexion suivante
 * (users.last_space_membership_id, présélection du sélecteur d'espace).
 */
class SelectSpace
{
    /**
     * @throws ModelNotFoundException rattachement inconnu, d'un autre utilisateur ou inutilisable
     */
    public function execute(User $user, string $membershipId): Membership
    {
        return DB::transaction(function () use ($user, $membershipId): Membership {
            $membership = $user->memberships()->usable()->with('organization')->findOrFail($membershipId);

            if ($user->last_space_membership_id !== $membership->id) {
                $user->last_space_membership_id = $membership->id;
                $user->save();
            }

            return $membership;
        });
    }
}
