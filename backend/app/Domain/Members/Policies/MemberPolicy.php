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

    public function seesPortfolio(Portfolio $portfolio): bool
    {
        return $portfolio === Portfolio::Cga || $this->context->allows('members.cabinet.view');
    }

    private function inCga(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga;
    }
}
