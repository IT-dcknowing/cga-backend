<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\MemberObligationSource;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Ajout d'une obligation à un dossier par le CGA (F-OBL-02, RG-OBL-02) : une obligation du catalogue
 * national en vigueur ou une obligation locale du centre (RG-OBL-01), confirmée d'office. Une même
 * obligation n'est attribuée qu'une fois tant qu'elle n'est pas retirée.
 */
class AddMemberObligation
{
    /**
     * @throws BusinessRuleException obligation_type_unavailable, obligation_already_assigned
     */
    public function execute(Member $member, ObligationType $type, User $actor): MemberObligation
    {
        return DB::transaction(function () use ($member, $type, $actor): MemberObligation {
            $available = ObligationType::query()->inForceFor($member->organization_id)->whereKey($type->id)->exists();
            if (! $available) {
                throw new BusinessRuleException('obligation_type_unavailable');
            }

            $assigned = MemberObligation::query()
                ->where('member_id', $member->id)
                ->where('obligation_type_id', $type->id)
                ->where('status', '!=', MemberObligationStatus::Removed)
                ->lockForUpdate()
                ->exists();
            if ($assigned) {
                throw new BusinessRuleException('obligation_already_assigned');
            }

            $obligation = new MemberObligation;
            $obligation->organization_id = $member->organization_id;
            $obligation->member_id = $member->id;
            $obligation->obligation_type_id = $type->id;
            $obligation->source = MemberObligationSource::Manual;
            $obligation->status = MemberObligationStatus::Active;
            $obligation->confirmed_by = $actor->id;
            $obligation->confirmed_at = now()->toImmutable();
            $obligation->start_date = today()->toImmutable();
            $obligation->save();

            return $obligation;
        });
    }
}
