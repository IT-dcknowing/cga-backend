<?php

namespace App\Domain\Users\Http\Controllers;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Actions\ResetRolePermissions;
use App\Domain\Users\Actions\SaveRolePermissions;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Http\Requests\RolePermissionsRequest;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\RolePermission;
use App\Domain\Users\Permissions\PermissionCatalog;
use App\Domain\Users\Permissions\PermissionLevel;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Domain\Users\Permissions\RoleMatrix;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Droits par rôle, une interface par rôle (F-ADM-05, 02-TRD §6.2) : catalogue, rôles paramétrables
 * avec leurs compteurs, droits d'un rôle groupés par module, écriture de la liste complète, retour
 * aux valeurs par défaut, aperçu de l'effet avant d'enregistrer. /api/v1/network/roles pour
 * l'administrateur de la plateforme, /api/v1/cga/roles pour le gérant.
 */
class RolePermissionController extends BaseApiController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RoleMatrix $matrix,
        private readonly PermissionCatalog $catalog,
    ) {}

    public function catalog(): JsonResponse
    {
        return $this->handle(function (): JsonResponse {
            Gate::authorize('viewCatalog', RolePermission::class);

            $permissions = array_map(fn (string $code): array => [
                'code' => $code,
                'label' => $this->catalog->label($code),
                'module' => $this->catalog->screenModule($code),
                'locked' => $this->catalog->isLocked($code),
                'default_roles' => $this->defaultRoles($code),
            ], $this->catalog->codes());
            $modules = array_map(static fn (string $module): array => ['module' => $module, 'label' => __("permission_modules.{$module}")], array_keys($this->catalog->screenModules()));

            return response()->json(['data' => ['modules' => $modules, 'permissions' => $permissions, 'locked_explanation' => __('permission_modules.locked')]]);
        }, action: 'permissions.catalog');
    }

    public function roles(Request $request, PermissionResolver $resolver): JsonResponse
    {
        return $this->handle(function () use ($request, $resolver): JsonResponse {
            $level = PermissionLevel::of($request);
            Gate::authorize('viewAny', [RolePermission::class, $level]);
            $cga = $this->cga($level);

            $roles = array_map(function (Role $role) use ($cga): array {
                $values = $this->matrix->values($role, $cga);

                return [
                    'role' => $role->value,
                    'label' => $role->label(),
                    'users_count' => $this->holders($role, $cga)->count(),
                    'granted_count' => count(array_filter($values, static fn (array $value): bool => $value['granted'])),
                    'permissions_count' => count($values),
                    'customized' => collect($values)->contains(static fn (array $value): bool => $value['customized']),
                ];
            }, $level->roles($resolver));

            return response()->json(['data' => $roles]);
        }, action: 'permissions.roles');
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        return $this->handle(function () use ($request, $role): JsonResponse {
            $level = PermissionLevel::of($request);
            Gate::authorize('manage', [RolePermission::class, $level, $role]);

            return response()->json(['data' => $this->rolePayload($level, $role)]);
        }, action: 'permissions.show');
    }

    public function update(RolePermissionsRequest $request, Role $role, SaveRolePermissions $save): JsonResponse
    {
        return $this->handle(function () use ($request, $role, $save): JsonResponse {
            $level = PermissionLevel::of($request);
            $save->execute($this->cga($level), $role, $request->granted(), $this->currentUser());

            return response()->json(['data' => $this->rolePayload($level, $role)]);
        }, action: 'permissions.update');
    }

    public function reset(Request $request, Role $role, ResetRolePermissions $reset): JsonResponse
    {
        return $this->handle(function () use ($request, $role, $reset): JsonResponse {
            $level = PermissionLevel::of($request);
            Gate::authorize('manage', [RolePermission::class, $level, $role]);
            $reset->execute($this->cga($level), $role, $this->currentUser());

            return response()->json(['data' => $this->rolePayload($level, $role)]);
        }, action: 'permissions.reset');
    }

    /**
     * Aperçu avant d'enregistrer (F-ADM-05) : utilisateurs concernés, droits ajoutés et retirés.
     */
    public function impact(RolePermissionsRequest $request, Role $role): JsonResponse
    {
        return $this->handle(function () use ($request, $role): JsonResponse {
            $cga = $this->cga(PermissionLevel::of($request));
            $values = array_filter($this->matrix->values($role, $cga), static fn (array $value): bool => ! $value['locked']);
            $wanted = array_intersect(array_keys($values), $request->granted());
            $current = array_keys(array_filter($values, static fn (array $value): bool => $value['granted']));
            $describe = fn (array $codes): array => array_values(array_map(fn (string $code): array => ['code' => $code, 'label' => $this->catalog->label($code)], $codes));

            return response()->json(['data' => [
                'role' => $role->value,
                'users_count' => $this->holders($role, $cga)->count(),
                'added' => $describe(array_diff($wanted, $current)),
                'removed' => $describe(array_diff($current, $wanted)),
            ]]);
        }, action: 'permissions.impact');
    }

    /**
     * @return array<string, mixed>
     */
    private function rolePayload(PermissionLevel $level, Role $role): array
    {
        $cga = $this->cga($level);
        $holders = $this->holders($role, $cga);

        return [
            'role' => $role->value,
            'label' => $role->label(),
            'level' => $level->value,
            'modules' => $this->matrix->byModule($this->matrix->values($role, $cga)),
            'users_count' => $holders->count(),
            // Liste nominative pour un CGA seulement : au réseau, un compteur.
            'users' => $cga === null ? null : $holders->with('user')->get()->map(static fn (Membership $m): array => [
                'id' => $m->user->id,
                'name' => $m->user->fullName(),
                'status' => $m->status->value,
            ])->values()->all(),
        ];
    }

    /**
     * Rattachements qui ont ce rôle, dans le CGA ou dans tout le réseau.
     *
     * @return Builder<Membership>
     */
    private function holders(Role $role, ?Organization $cga): Builder
    {
        return Membership::query()
            ->where('role', $role)
            ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Invited, MembershipStatus::ReadOnly])
            ->when($cga !== null, static fn (Builder $query) => $query->where('organization_id', $cga?->id));
    }

    private function cga(PermissionLevel $level): ?Organization
    {
        return $level === PermissionLevel::Cga ? $this->context->organization() : null;
    }

    /**
     * @return list<string>
     */
    private function defaultRoles(string $code): array
    {
        return array_values(array_map(
            static fn (Role $role): string => $role->value,
            array_filter(Role::cases(), fn (Role $role): bool => $this->catalog->grantsByDefault($role, $code)),
        ));
    }
}
