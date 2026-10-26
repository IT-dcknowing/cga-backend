<?php

namespace App\Domain\Users\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\TeamRoles;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Réactivation de l'accès d'un agent désactivé : ses rôles d'agent reviennent, actifs pour un compte
 * actif, invités sinon (le lien se renvoie à part).
 */
class EnableAgent
{
    /**
     * @throws BusinessRuleException agent_not_disabled
     */
    public function execute(Organization $organization, User $agent): User
    {
        return DB::transaction(function () use ($organization, $agent): User {
            $memberships = $agent->memberships()
                ->where('organization_id', $organization->id)
                ->whereNull('member_id')
                ->whereIn('role', TeamRoles::assignable($organization->type))
                ->lockForUpdate()
                ->get();
            if ($memberships->isEmpty() || $memberships->contains(fn (Membership $m): bool => $m->status !== MembershipStatus::Disabled)) {
                throw new BusinessRuleException('agent_not_disabled');
            }

            $status = $agent->status === UserStatus::Active ? MembershipStatus::Active : MembershipStatus::Invited;
            $memberships->each(static function (Membership $membership) use ($status): void {
                $membership->status = $status;
                $membership->activated_at = $status === MembershipStatus::Active ? now() : $membership->activated_at;
                $membership->disabled_at = null;
                $membership->save();
            });

            return $agent;
        });
    }
}
