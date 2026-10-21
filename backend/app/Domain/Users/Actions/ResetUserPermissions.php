<?php

namespace App\Domain\Users\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Domain\Users\Models\UserPermissionOverride;
use App\Domain\Users\Permissions\UserPermissionCloud;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Retour d'un agent aux droits de son profil (RG-ROLE-07) : ses ajustements individuels dans
 * l'organisation sont supprimés. Journalisé, effet immédiat. Nul ne le fait pour lui-même.
 */
class ResetUserPermissions
{
    public function __construct(
        private readonly UserPermissionCloud $cloud,
        private readonly SaveUserPermissions $save,
    ) {}

    /**
     * @throws BusinessRuleException cannot_manage_self
     */
    public function execute(Organization $organization, User $agent, User $actor): void
    {
        if ($agent->is($actor)) {
            throw new BusinessRuleException('cannot_manage_self');
        }

        $this->save->inOrganization($organization, function () use ($organization, $agent): void {
            DB::transaction(function () use ($organization, $agent): void {
                $memberships = array_map(static fn ($m): string => $m->id, $this->cloud->memberships($organization, $agent));
                UserPermissionOverride::query()->whereIn('membership_id', $memberships)->get()->each->delete();
            });
        });
    }
}
