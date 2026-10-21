<?php

namespace App\Domain\Users\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\TeamRoles;
use App\Support\Tenancy\TenantContext;

/**
 * Agents d'une organisation (PRD §3.3) : le gérant gère ceux de son CGA (cga_users.manage), la
 * Présidente ceux du CNCGA (network_users.manage), droits verrouillés. L'espace de la route (/cga ou
 * /network) doit être celui de l'espace actif. Le responsable de l'organisation (gérant, Présidente)
 * n'est jamais géré par ses agents.
 */
class AgentPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $actor, OrganizationType $space): bool
    {
        return $this->manages($space);
    }

    public function create(User $actor, OrganizationType $space): bool
    {
        return $this->manages($space);
    }

    public function view(User $actor, User $agent, OrganizationType $space): bool
    {
        return $this->manages($space);
    }

    /**
     * Rôles, désactivation, réactivation, renvoi de l'invitation. Sur son propre accès, l'Action
     * répond 409 cannot_manage_self.
     */
    public function update(User $actor, User $agent, OrganizationType $space): bool
    {
        $head = TeamRoles::head($space);

        return $this->manages($space)
            && ! $agent->memberships->contains(fn (Membership $m): bool => $m->organization_id === $this->context->organizationId() && $m->role === $head);
    }

    private function manages(OrganizationType $space): bool
    {
        $permission = TeamRoles::permission($space);

        return $permission !== null
            && $this->context->has()
            && $this->context->organizationType() === $space
            && $this->context->allows($permission);
    }
}
