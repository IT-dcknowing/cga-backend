<?php

namespace App\Domain\Users\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\AgentDirectory;
use App\Domain\Users\Support\TeamRoles;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rôles d'un agent dans l'organisation (F-ORG-09) : la liste donnée remplace ses rôles d'agent. Un
 * rôle retiré est supprimé, avec ses ajustements individuels (le journal des modifications garde
 * l'historique) ; un rôle ajouté suit l'état de l'agent (actif, invité, désactivé). Le rôle de
 * responsable n'est jamais touché ici. Les droits en cache sont invalidés à chaque changement
 * (RG-ROLE-05). Nul ne change ses propres rôles.
 */
class ChangeAgentRoles
{
    /**
     * @param  non-empty-list<Role>  $roles  rôles d'agent attribuables dans cette organisation
     *
     * @throws BusinessRuleException cannot_manage_self
     */
    public function execute(Organization $organization, User $agent, array $roles, User $actor): User
    {
        if ($agent->is($actor)) {
            throw new BusinessRuleException('cannot_manage_self');
        }

        return DB::transaction(function () use ($organization, $agent, $roles, $actor): User {
            $current = $agent->memberships()->where('organization_id', $organization->id)->whereNull('member_id')->lockForUpdate()->get();
            $assignable = TeamRoles::assignable($organization->type);
            $state = $this->stateFor($agent, $current->filter(fn (Membership $m): bool => in_array($m->role, $assignable, true))->values());

            foreach ($assignable as $role) {
                $membership = $current->first(fn (Membership $m): bool => $m->role === $role);
                $wanted = in_array($role, $roles, true);

                if ($wanted && $membership === null) {
                    $this->create($agent, $organization, $role, $state, $actor);
                } elseif (! $wanted && $membership !== null) {
                    $membership->delete();
                }
            }

            return $agent;
        });
    }

    /**
     * État d'un rôle ajouté : celui de l'agent (désactivé s'il l'est), sinon actif pour un compte
     * actif, invité pour un compte qui n'a pas encore activé son accès.
     *
     * @param  Collection<int, Membership>  $agentMemberships
     */
    private function stateFor(User $agent, Collection $agentMemberships): MembershipStatus
    {
        if ($agentMemberships->isNotEmpty() && AgentDirectory::status($agentMemberships) === MembershipStatus::Disabled) {
            return MembershipStatus::Disabled;
        }

        return $agent->status === UserStatus::Active ? MembershipStatus::Active : MembershipStatus::Invited;
    }

    private function create(User $agent, Organization $organization, Role $role, MembershipStatus $state, User $actor): void
    {
        $membership = new Membership;
        $membership->user_id = $agent->id;
        $membership->organization_id = $organization->id;
        $membership->role = $role;
        $membership->invited_by = $actor->id;
        $membership->status = $state;
        $membership->activated_at = $state === MembershipStatus::Active ? now() : null;
        $membership->disabled_at = $state === MembershipStatus::Disabled ? now() : null;
        $membership->save();
    }
}
