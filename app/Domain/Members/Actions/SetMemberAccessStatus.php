<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * « Désactiver » puis réactiver un accès en ligne de l'adhérent (F-ADH-09). La session de cet accès
 * s'arrête à la requête suivante (02-TRD §6.2). Réactivé, l'accès retrouve son état : actif s'il a
 * été activé, sinon en invitation ; celui d'un adhérent radié ne se réactive pas (RG-ADH-12).
 */
class SetMemberAccessStatus
{
    /**
     * @throws BusinessRuleException access_already_disabled
     */
    public function disable(Membership $access): Membership
    {
        return DB::transaction(function () use ($access): Membership {
            $access = Membership::query()->lockForUpdate()->findOrFail($access->id);
            if ($access->status === MembershipStatus::Disabled) {
                throw new BusinessRuleException('access_already_disabled');
            }

            $access->status = MembershipStatus::Disabled;
            $access->disabled_at = now()->toImmutable();
            $access->save();

            return $access;
        });
    }

    /**
     * @throws BusinessRuleException access_not_disabled, member_closed, member_owner_exists
     */
    public function enable(Member $member, Membership $access): Membership
    {
        return DB::transaction(function () use ($member, $access): Membership {
            $access = Membership::query()->lockForUpdate()->findOrFail($access->id);
            if ($access->status !== MembershipStatus::Disabled) {
                throw new BusinessRuleException('access_not_disabled');
            }
            if (! in_array($member->status, [MemberStatus::Active, MemberStatus::RadiationPending], true)) {
                throw new BusinessRuleException('member_closed');
            }
            $otherOwner = $access->role === Role::MemberOwner && Membership::query()
                ->where('member_id', $member->id)
                ->where('role', Role::MemberOwner)
                ->whereKeyNot($access->id)
                ->where('status', '!=', MembershipStatus::Disabled)
                ->exists();
            if ($otherOwner) {
                throw new BusinessRuleException('member_owner_exists');
            }

            $access->status = $access->activated_at === null ? MembershipStatus::Invited : MembershipStatus::Active;
            $access->disabled_at = null;
            $access->save();

            return $access;
        });
    }
}
