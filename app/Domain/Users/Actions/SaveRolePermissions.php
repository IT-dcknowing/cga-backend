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
 * Droits d'un rôle (F-ADM-05) : la liste donnée est celle des droits accordés. Au réseau, chaque
 * droit paramétrable du rôle a sa ligne (RG-ROLE-02) ; pour un CGA, seuls les écarts au réseau sont
 * gardés (RG-ROLE-03 : « personnalisé pour votre centre »). Les droits verrouillés et ceux hors du
 * rôle sont ignorés (RG-ROLE-04). Chaque ligne écrite est journalisée avec sa valeur avant et après,
 * et les droits en cache sont invalidés : effet immédiat (RG-ROLE-05).
 */
class SaveRolePermissions
{
    public function __construct(
        private readonly RoleMatrix $matrix,
        private readonly PermissionCatalog $catalog,
    ) {}

    /**
     * @param  Organization|null  $cga  null : paramétrage réseau
     * @param  list<string>  $granted
     */
    public function execute(?Organization $cga, Role $role, array $granted, User $actor): void
    {
        DB::transaction(function () use ($cga, $role, $granted, $actor): void {
            foreach ($this->matrix->values($role, $cga) as $code => $value) {
                if ($value['locked']) {
                    continue;
                }

                $wanted = in_array($code, $granted, true);
                if ($cga !== null && $wanted === $value['reference']) {
                    $this->row($cga, $role, $code)?->delete();
                } elseif ($cga !== null || $wanted !== $value['granted'] || $this->row(null, $role, $code) === null) {
                    $this->write($cga, $role, $code, $wanted, $actor);
                }
            }
        });
    }

    private function row(?Organization $cga, Role $role, string $code): ?RolePermission
    {
        return RolePermission::query()
            ->where('organization_id', $cga?->id)
            ->where('role', $role)
            ->where('permission', $code)
            ->lockForUpdate()
            ->first();
    }

    private function write(?Organization $cga, Role $role, string $code, bool $granted, User $actor): void
    {
        $this->catalog->assertWritable($code);

        $row = $this->row($cga, $role, $code) ?? new RolePermission;
        $row->organization_id = $cga?->id;
        $row->role = $role;
        $row->permission = $code;
        $row->granted = $granted;
        $row->setAttribute('updated_by', $actor->id);
        $row->save();
    }
}
