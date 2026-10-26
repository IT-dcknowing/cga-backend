<?php

namespace App\Domain\Users\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\TeamRoles;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Désactivation de l'accès d'un agent à l'organisation (F-ORG-09) : tous ses rôles d'agent, en une
 * fois (la réactivation les rend tous) ; son compte et ses accès ailleurs ne changent pas. Sa session dans cet espace s'arrête à la requête suivante (02-TRD §6.2). Nul ne
 * désactive son propre accès.
 */
class DisableAgent
{
    /**
     * @throws BusinessRuleException cannot_manage_self, agent_already_disabled
     */
    public function execute(Organization $organization, User $agent, User $actor): User
    {
        if ($agent->is($actor)) {
            throw new BusinessRuleException('cannot_manage_self');
        }

        return DB::transaction(function () use ($organization, $agent): User {
            $enabled = $agent->memberships()
                ->where('organization_id', $organization->id)
                ->whereNull('member_id')
                ->whereIn('role', TeamRoles::assignable($organization->type))
                ->where('status', '!=', MembershipStatus::Disabled)
                ->lockForUpdate()
                ->get();
            if ($enabled->isEmpty()) {
                throw new BusinessRuleException('agent_already_disabled');
            }

            $now = now();
            $enabled->each(static function (Membership $membership) use ($now): void {
                $membership->status = MembershipStatus::Disabled;
                $membership->disabled_at = $now;
                $membership->save();
            });

            return $agent;
        });
    }
}
