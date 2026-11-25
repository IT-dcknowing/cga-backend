<?php

namespace App\Domain\Users\Permissions;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Enums\Role;
use Illuminate\Http\Request;

/**
 * Niveau du paramétrage des droits par rôle (RG-ROLE-02, 03) : le réseau, par l'administrateur de
 * la plateforme (tous les rôles sauf le sien), ou un CGA, par son gérant (rôles de son centre hors
 * gérant). Routes /api/v1/network/roles et /api/v1/cga/roles.
 */
enum PermissionLevel: string
{
    case Network = 'network';
    case Cga = 'cga';

    public static function of(Request $request): self
    {
        return str_starts_with((string) $request->route()?->getName(), 'network.') ? self::Network : self::Cga;
    }

    /**
     * Type de l'organisation de l'espace d'où le niveau se paramètre.
     */
    public function spaceType(): OrganizationType
    {
        return match ($this) {
            self::Network => OrganizationType::Platform,
            self::Cga => OrganizationType::Cga,
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::Network => 'network_permissions.manage',
            self::Cga => 'cga_permissions.manage',
        };
    }

    /**
     * @return list<Role>
     */
    public function roles(PermissionResolver $resolver): array
    {
        return array_values(array_filter(Role::cases(), fn (Role $role): bool => match ($this) {
            self::Network => $role !== Role::PlatformAdmin,
            self::Cga => $resolver->cgaMayOverride($role),
        }));
    }
}
