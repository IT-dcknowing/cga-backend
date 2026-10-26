<?php

namespace App\Domain\Users\Data;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Users\Models\User;

/**
 * Résultat d'une invitation : le compte, et le lien d'activation envoyé (null pour un compte déjà
 * actif, qui reçoit seulement l'avis de son nouvel accès). Le lien n'est rendu qu'au contrôleur, pour
 * la recopie de développement (DevMessages).
 */
final readonly class InvitedAgent
{
    public function __construct(
        public User $user,
        #[\SensitiveParameter] public ?string $link,
        public InvitationChannel $channel,
    ) {}
}
