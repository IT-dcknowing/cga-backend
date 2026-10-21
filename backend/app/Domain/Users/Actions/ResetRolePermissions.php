<?php

namespace App\Domain\Users\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\RolePermission;
use App\Domain\Users\Models\User;
use App\Domain\Users\Permissions\PermissionCatalog;
use App\Domain\Users\Permissions\RoleMatrix;
use Illuminate\Support\Facades\DB;

/**
 * « Revenir aux droits par défaut » pour un rôle (RG-ROLE-06) : au réseau, la matrice par défaut du
 * PRD §3.3 (RG-ROLE-01) ; pour un CGA, le paramétrage réseau (ses ajustements sont supprimés).
 * Journalisé, effet immédiat (RG-ROLE-05).
 */
class ResetRolePermissions
{
    public function __construct(
        private readonly SaveRolePermissions $save,
        private readonly RoleMatrix $matrix,
        private readonly PermissionCatalog $catalog,
    ) {}

    public function execute(?Organization $cga, Role $role, User $actor): void
    {
        DB::transaction(function () use ($cga, $role, $actor): void {
            if ($cga !== null) {
                RolePermission::query()->where('organization_id', $cga->id)->where('role', $role)->get()->each->delete();

                return;
            }

            $defaults = array_values(array_filter($this->matrix->relevant($role), fn (string $code): bool => $this->catalog->grantsByDefault($role, $code)));
            $this->save->execute(null, $role, $defaults, $actor);
        });
    }
}
