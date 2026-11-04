<?php

namespace App\Domain\Concerns\Policies;

use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Support\ConcernAccess;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Préoccupations (PRD M10, matrice §3.3) : listes de chaque espace, lecture, réponse, note interne,
 * statut, réouverture par le demandeur, transfert au CNCGA (F-PRE-03), tableaux des délais (F-PRE-04).
 * Le côté de l'espace actif dans une demande vient de ConcernAccess.
 */
class ConcernPolicy
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ConcernAccess $access,
    ) {}

    /** Onglet « Reçues » du centre : demandes de ses adhérents. */
    public function viewReceived(User $user): bool
    {
        return $this->access->inCgaAgentSpace() && $this->context->allows('concerns.reply');
    }

    /** Onglet « Envoyées au CNCGA » et « Écrire au CNCGA ». */
    public function writeToNetwork(User $user): bool
    {
        return $this->access->inCgaAgentSpace() && $this->context->allows('concerns.network.write');
    }

    /** Tableau des délais du centre (F-PRE-04). */
    public function viewIndicators(User $user): bool
    {
        return $this->access->inCgaAgentSpace() && $this->context->allows('concerns.indicators.view');
    }

    /** « Mes demandes » et « Nouvelle demande » de l'adhérent (F-PRE-01, F-DOC-06). */
    public function writeOwn(User $user): bool
    {
        return $this->access->inMemberSpace() && $this->context->allows('member.concerns.write');
    }

    /** Demandes des CGA et leurs chiffres (F-PRE-02, F-PRE-04, RG-PRE-03). */
    public function viewNetwork(User $user): bool
    {
        return $this->access->inNetwork() && $this->context->allows('network_concerns.view');
    }

    public function view(User $user, Concern $concern): bool
    {
        return $this->access->side($concern) !== null;
    }

    /** Répondre au fil : le demandeur, ou qui traite la demande avec le droit de la traiter. */
    public function reply(User $user, Concern $concern): bool
    {
        return match ($this->access->side($concern)) {
            ConcernSide::Requester => true,
            ConcernSide::Handler => $this->access->handles($concern),
            null => false,
        };
    }

    /** Note interne et changement de statut : qui traite la demande. */
    public function handle(User $user, Concern $concern): bool
    {
        return $this->access->handles($concern);
    }

    /** « Rouvrir la demande » : le demandeur seulement. */
    public function reopen(User $user, Concern $concern): bool
    {
        return $this->access->side($concern) === ConcernSide::Requester;
    }

    /** « Transmettre au CNCGA » (F-PRE-03). */
    public function transfer(User $user, Concern $concern): bool
    {
        return $this->access->transfers($concern);
    }
}
