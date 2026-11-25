<?php

namespace App\Domain\Users\Support;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Agents d'une organisation : comptes qui y ont au moins un rôle hors accès adhérent, avec leurs
 * rôles dans cette organisation seulement et leurs invitations. Un compte sans rôle dans
 * l'organisation active n'en fait pas partie (404, cloisonnement).
 */
class AgentDirectory
{
    /**
     * @return Builder<User>
     */
    public function query(Organization $organization): Builder
    {
        return User::query()
            ->whereHas('memberships', fn (Builder $memberships) => $this->inOrganization($memberships, $organization))
            ->with(['memberships' => fn (HasMany $memberships) => $this->inOrganization($memberships->getQuery(), $organization)->with('invitations')->orderBy('id')]);
    }

    /**
     * @throws ModelNotFoundException compte sans rôle d'agent dans l'organisation
     */
    public function find(Organization $organization, string $id): User
    {
        return $this->query($organization)->whereKey($id)->firstOrFail();
    }

    /**
     * État de l'agent dans l'organisation : actif s'il a un rôle actif, invité s'il n'a que des rôles en
     * attente, désactivé sinon.
     *
     * @param  Collection<int, Membership>  $memberships
     */
    public static function status(Collection $memberships): MembershipStatus
    {
        return match (true) {
            $memberships->contains(fn (Membership $m): bool => in_array($m->status, [MembershipStatus::Active, MembershipStatus::ReadOnly], true)) => MembershipStatus::Active,
            $memberships->contains(fn (Membership $m): bool => $m->status === MembershipStatus::Invited) => MembershipStatus::Invited,
            default => MembershipStatus::Disabled,
        };
    }

    /**
     * @template TBuilder of Builder<Membership>
     *
     * @param  TBuilder  $memberships
     * @return TBuilder
     */
    private function inOrganization(Builder $memberships, Organization $organization): Builder
    {
        return $memberships->where('organization_id', $organization->id)->whereNull('member_id');
    }
}
