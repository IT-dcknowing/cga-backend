<?php

namespace App\Domain\Auth\Support;

use App\Domain\Auth\Models\Invitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Invitation désignée par le jeton secret du lien /activer/{jeton} (F-AUTH-02), jamais par son
 * identifiant. Résolue par la liaison de route « invitationToken » : un jeton inconnu répond 404
 * avant toute validation, sans dire s'il a existé (A07).
 */
final readonly class InvitationToken
{
    private function __construct(public Invitation $invitation) {}

    /**
     * @throws ModelNotFoundException jeton inconnu
     */
    public static function resolve(string $token): self
    {
        $invitation = Invitation::query()
            ->with(['membership.organization', 'membership.user'])
            ->where('token_hash', Invitation::fingerprint($token))
            ->first();

        return $invitation === null
            ? throw (new ModelNotFoundException)->setModel(Invitation::class)
            : new self($invitation);
    }
}
