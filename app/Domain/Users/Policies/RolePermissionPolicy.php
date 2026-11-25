<?php

namespace App\Domain\Users\Policies;

use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Permissions\PermissionLevel;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Support\Tenancy\TenantContext;

/**
 * Paramétrage des droits par rôle (RG-ROLE-02, 03) : le réseau par l'administrateur de la plateforme
 * (network_permissions.manage), un CGA par son gérant (cga_permissions.manage), droits verrouillés,
 * chacun depuis l'espace de son organisation. Le catalogue est lu par ceux qui paramètrent des
 * droits (rôles ou utilisateurs).
 */
class RolePermissionPolicy
{
    private const CATALOG_READERS = ['network_permissions.manage', 'cga_permissions.manage', 'network_user_permissions.manage', 'cga_user_permissions.manage'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionResolver $resolver,
    ) {}

    public function viewCatalog(User $user): bool
    {
        return $this->context->has() && collect(self::CATALOG_READERS)->contains(fn (string $permission): bool => $this->context->allows($permission));
    }

    public function viewAny(User $user, PermissionLevel $level): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === $level->spaceType()
            && $this->context->allows($level->permission());
    }

    /**
     * Rôle paramétrable à ce niveau : tous sauf platform_admin au réseau, ceux du centre hors gérant
     * pour un CGA (le gérant ne modifie pas ses propres droits, RG-ROLE-03).
     */
    public function manage(User $user, PermissionLevel $level, Role $role): bool
    {
        return $this->viewAny($user, $level) && in_array($role, $level->roles($this->resolver), true);
    }
}
