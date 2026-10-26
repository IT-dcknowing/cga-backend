<?php

namespace App\Domain\Members\Policies;

use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Dossiers des adhérents (PRD §3.3) : chaque agent du CGA voit tous les adhérents du centre
 * (RG-ACC-07) avec members.view, inscrit avec members.create, modifie avec members.update ; le
 * portefeuille Cabinet demande en plus members.cabinet.view (RG-PTF-02). Le cloisonnement garde
 * chaque dossier dans son centre.
 */
class MemberPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->inCga() && $this->context->allows('members.view');
    }

    public function view(User $user, Member $member): bool
    {
        return $this->viewAny($user) && $this->seesPortfolio($member->portfolio);
    }

    /**
     * Accueil de l'espace adhérent (F-DSH-03) : son propre dossier, depuis un accès adhérent actif.
     */
    public function viewOwn(User $user): bool
    {
        return $this->inCga() && $this->context->memberId() !== null;
    }

    /**
     * Liste d'un portefeuille (RG-PTF-02).
     */
    public function viewPortfolio(User $user, Portfolio $portfolio): bool
    {
        return $this->viewAny($user) && $this->seesPortfolio($portfolio);
    }

    public function create(User $user, Portfolio $portfolio): bool
    {
        return $this->inCga() && $this->context->allows('members.create') && $this->seesPortfolio($portfolio);
    }

    public function update(User $user, Member $member): bool
    {
        return $this->inCga() && $this->context->allows('members.update') && $this->seesPortfolio($member->portfolio);
    }

    /**
     * « Valider l'adhésion » (F-ADH-02) : ceux qui inscrivent les adhérents.
     */
    public function validate(User $user, Member $member): bool
    {
        return $this->inCga() && $this->context->allows('members.create') && $this->seesPortfolio($member->portfolio);
    }

    /**
     * Brouillon à supprimer (RG-ADH-01) : celui qui modifie le dossier.
     */
    public function delete(User $user, Member $member): bool
    {
        return $this->update($user, $member);
    }

    /**
     * « Déposer » une pièce du dossier (F-PJ-01) : ceux qui modifient le dossier.
     */
    public function managePieces(User $user, Member $member): bool
    {
        return $this->update($user, $member);
    }

    /**
     * Accès en ligne de l'adhérent (F-ADH-09) : invitation, renvoi, désactivation par le gérant.
     */
    public function manageAccesses(User $user, Member $member): bool
    {
        return $this->inCga() && $this->context->allows('members.access.manage') && $this->seesPortfolio($member->portfolio);
    }

    /**
     * « Réimporter depuis un autre CGA » (RG-ADH-15) : ceux qui inscrivent les adhérents.
     */
    public function reimport(User $user): bool
    {
        return $this->inCga() && $this->context->allows('members.create');
    }

    /**
     * « Changer de régime » (F-ADH-05) : gérant, expert-comptable, comptables, comme la modification.
     */
    public function changeRegime(User $user, Member $member): bool
    {
        return $this->update($user, $member);
    }

    /**
     * « Demander la radiation » (F-ADH-06) : gérant, expert-comptable.
     */
    public function requestRadiation(User $user, Member $member): bool
    {
        return $this->inCga() && $this->context->allows('members.radiation.request') && $this->seesPortfolio($member->portfolio);
    }

    /**
     * « Valider la radiation » (RG-ADH-17) : gérant, expert-comptable.
     */
    public function validateRadiation(User $user, Member $member): bool
    {
        return $this->inCga() && $this->context->allows('members.radiation.validate') && $this->seesPortfolio($member->portfolio);
    }

    /**
     * « Annuler la radiation » (RG-ADH-11) : le gérant seulement (permission verrouillée).
     */
    public function cancelRadiation(User $user, Member $member): bool
    {
        return $this->inCga() && $this->context->allows('members.radiation.cancel') && $this->seesPortfolio($member->portfolio);
    }

    /**
     * Issue « basculer dans le portefeuille Cabinet » d'une radiation (RG-PTF-04) : le droit de basculer
     * et la vue du portefeuille Cabinet.
     */
    public function moveToCabinet(User $user, Member $member): bool
    {
        return $this->inCga() && $this->context->allows('members.portfolio.move') && $this->seesPortfolio(Portfolio::Cabinet);
    }

    /**
     * « Basculer vers le portefeuille CGA » un dossier du Cabinet (RG-PTF-03) : le droit de basculer.
     */
    public function moveToCga(User $user, Member $member): bool
    {
        return $this->inCga() && $this->context->allows('members.portfolio.move') && $this->seesPortfolio($member->portfolio);
    }

    public function seesPortfolio(Portfolio $portfolio): bool
    {
        return $portfolio === Portfolio::Cga || $this->context->allows('members.cabinet.view');
    }

    private function inCga(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga;
    }
}
