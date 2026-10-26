<?php

namespace App\Domain\Users\Permissions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\RolePermission;

/**
 * Droits d'un rôle à un niveau (F-ADM-05, RG-ROLE-01 à 04) : ceux qui concernent son espace (droits
 * accordés par défaut à un rôle de la même famille : CNCGA, CGA, adhérent), avec pour chacun la
 * valeur à ce niveau, la valeur de référence (matrice par défaut pour le réseau, paramétrage réseau
 * pour un CGA), le verrou et l'écart à la référence (« personnalisé »).
 */
class RoleMatrix
{
    public function __construct(private readonly PermissionCatalog $catalog) {}

    /**
     * @return list<string>
     */
    public function relevant(Role $role): array
    {
        $family = array_filter(Role::cases(), fn (Role $other): bool => $this->family($other) === $this->family($role));

        return array_values(array_filter(
            $this->catalog->codes(),
            fn (string $code): bool => collect($family)->contains(fn (Role $other): bool => $this->catalog->grantsByDefault($other, $code)),
        ));
    }

    /**
     * @param  Organization|null  $cga  null : paramétrage réseau
     * @return array<string, array{granted: bool, reference: bool, locked: bool, customized: bool}>
     */
    public function values(Role $role, ?Organization $cga): array
    {
        $rows = RolePermission::query()
            ->where('role', $role)
            ->where(fn ($query) => $cga === null ? $query->whereNull('organization_id') : $query->whereNull('organization_id')->orWhere('organization_id', $cga->id))
            ->get();
        $network = $rows->whereNull('organization_id')->pluck('granted', 'permission');
        $override = $cga === null ? collect() : $rows->where('organization_id', $cga->id)->pluck('granted', 'permission');

        $values = [];
        foreach ($this->relevant($role) as $code) {
            $default = $this->catalog->grantsByDefault($role, $code);
            if ($this->catalog->isLocked($code)) {
                $values[$code] = ['granted' => $default, 'reference' => $default, 'locked' => true, 'customized' => false];

                continue;
            }

            $networkValue = (bool) ($network[$code] ?? $default);
            $reference = $cga === null ? $default : $networkValue;
            $granted = $cga === null ? $networkValue : (bool) ($override[$code] ?? $networkValue);
            $values[$code] = ['granted' => $granted, 'reference' => $reference, 'locked' => false, 'customized' => $granted !== $reference];
        }

        return $values;
    }

    /**
     * Valeurs regroupées par module de l'écran, dans l'ordre des modules.
     *
     * @param  array<string, array{granted: bool, reference: bool, locked: bool, customized: bool}>  $values
     * @return list<array{module: string, label: string, permissions: list<array{code: string, label: string, granted: bool, reference: bool, locked: bool, customized: bool}>}>
     */
    public function byModule(array $values): array
    {
        $modules = [];
        foreach (array_keys($this->catalog->screenModules()) as $module) {
            $permissions = [];
            foreach ($values as $code => $value) {
                if ($this->catalog->screenModule($code) === $module) {
                    $permissions[] = ['code' => $code, 'label' => $this->catalog->label($code), ...$value];
                }
            }
            if ($permissions !== []) {
                $modules[] = ['module' => $module, 'label' => __("permission_modules.{$module}"), 'permissions' => $permissions];
            }
        }

        return $modules;
    }

    /**
     * Famille d'un rôle : CNCGA, CGA (agents), adhérent, plateforme.
     */
    private function family(Role $role): string
    {
        return $role->isMemberRole() ? 'member' : $role->organizationType()->value;
    }
}
