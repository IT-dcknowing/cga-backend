<?php

namespace App\Domain\Users\Http\Controllers;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Actions\ResetUserPermissions;
use App\Domain\Users\Actions\SaveUserPermissions;
use App\Domain\Users\Http\Requests\ListNetworkUsersRequest;
use App\Domain\Users\Http\Requests\UserPermissionsRequest;
use App\Domain\Users\Http\Resources\AgentResource;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Permissions\PermissionCatalog;
use App\Domain\Users\Permissions\UserPermissionCloud;
use App\Domain\Users\Support\AgentDirectory;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Habilitations par utilisateur, le « nuage » (RG-ROLE-07, F-ADM-05) : /api/v1/cga/users/{agent}/permissions
 * pour le gérant (agents de son centre), /api/v1/platform/network-users pour l'administrateur de la
 * plateforme (utilisateurs du CNCGA). Lecture avec la valeur et l'origine de chaque droit, écriture,
 * retour au profil.
 */
class UserPermissionController extends BaseApiController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly UserPermissionCloud $cloud,
        private readonly PermissionCatalog $catalog,
    ) {}

    /**
     * Espace des agents visés : un CGA (route /cga), ou le CNCGA (route /platform).
     */
    public static function spaceOf(Request $request): OrganizationType
    {
        return str_starts_with((string) $request->route()?->getName(), 'platform.') ? OrganizationType::Cncga : OrganizationType::Cga;
    }

    /**
     * GET /api/v1/platform/network-users : utilisateurs du CNCGA, pour leurs habilitations.
     */
    public function networkUsers(ListNetworkUsersRequest $request, AgentDirectory $directory, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $directory, $settings): AnonymousResourceCollection {
            $users = QueryBuilder::for($directory->query(self::network()), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('last_name')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return AgentResource::collection($users);
        }, action: 'permissions.users.index');
    }

    public function show(Request $request, User $agent): JsonResponse
    {
        return $this->handle(function () use ($request, $agent): JsonResponse {
            Gate::authorize('adjustPermissions', [$agent, self::spaceOf($request)]);

            return response()->json(['data' => $this->payload($request, $agent)]);
        }, action: 'permissions.users.show');
    }

    public function update(UserPermissionsRequest $request, User $agent, SaveUserPermissions $save): JsonResponse
    {
        return $this->handle(function () use ($request, $agent, $save): JsonResponse {
            $save->execute($this->organization($request), $agent, $request->granted(), $request->reason(), $this->currentUser());

            return response()->json(['data' => $this->payload($request, $agent)]);
        }, action: 'permissions.users.update');
    }

    public function reset(Request $request, User $agent, ResetUserPermissions $reset): JsonResponse
    {
        return $this->handle(function () use ($request, $agent, $reset): JsonResponse {
            Gate::authorize('adjustPermissions', [$agent, self::spaceOf($request)]);
            $reset->execute($this->organization($request), $agent, $this->currentUser());

            return response()->json(['data' => $this->payload($request, $agent)]);
        }, action: 'permissions.users.reset');
    }

    /**
     * Le CNCGA, organisation des utilisateurs dont l'administrateur règle les habilitations.
     */
    public static function network(): Organization
    {
        return Organization::query()->where('type', OrganizationType::Cncga)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, User $agent): array
    {
        $cloud = $this->cloud->of($this->organization($request), $agent);
        $modules = [];
        foreach (array_keys($this->catalog->screenModules()) as $module) {
            $permissions = array_values(array_filter($cloud, static fn (array $permission): bool => $permission['module'] === $module));
            if ($permissions !== []) {
                $modules[] = ['module' => $module, 'label' => __("permission_modules.{$module}"), 'permissions' => $permissions];
            }
        }

        return [
            'user' => ['id' => $agent->id, 'name' => $agent->fullName()],
            'roles' => array_map(static fn (Membership $m): array => ['role' => $m->role->value, 'role_label' => $m->role->label()], $this->cloud->memberships($this->organization($request), $agent)),
            'adjusted_count' => count(array_filter($cloud, static fn (array $permission): bool => $permission['adjusted'])),
            'modules' => $modules,
        ];
    }

    private function organization(Request $request): Organization
    {
        return self::spaceOf($request) === OrganizationType::Cncga ? self::network() : $this->context->organization();
    }
}
