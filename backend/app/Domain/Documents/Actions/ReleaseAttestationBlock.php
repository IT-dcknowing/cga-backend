<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Déblocage de l'attestation d'un adhérent par le gérant (RG-DOC-10), motif obligatoire : le blocage
 * en cours est clos (historisé), l'attestation peut de nouveau être produite.
 */
class ReleaseAttestationBlock
{
    /**
     * @throws BusinessRuleException attestation_not_blocked
     */
    public function execute(Member $member, string $reason, User $actor): AttestationBlock
    {
        return DB::transaction(function () use ($member, $reason, $actor): AttestationBlock {
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            $block = AttestationBlock::query()->where('member_id', $member->id)->whereNull('unblocked_at')->lockForUpdate()->first()
                ?? throw new BusinessRuleException('attestation_not_blocked');

            $block->unblocked_by = $actor->id;
            $block->unblocked_at = now()->toImmutable();
            $block->unblock_reason = $reason;
            $block->save();

            $member->attestation_blocked = false;
            $member->save();

            return $block;
        });
    }
}
