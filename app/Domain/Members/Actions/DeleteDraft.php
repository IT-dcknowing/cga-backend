<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Actions\SyncEntryFee;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Suppression d'un brouillon (RG-ADH-01) : un dossier jamais validé, sans numéro, s'efface
 * (suppression logique, 05-BACKEND-SCHEMA §17). Les documents émis arrivent au Lot 3 : un brouillon
 * n'en a jamais.
 */
class DeleteDraft
{
    public function __construct(private readonly SyncEntryFee $entryFee) {}

    /**
     * @throws BusinessRuleException member_not_draft
     */
    public function execute(Member $member): void
    {
        DB::transaction(function () use ($member): void {
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            if ($member->status !== MemberStatus::Draft) {
                throw new BusinessRuleException('member_not_draft');
            }

            $member->delete();
            $this->entryFee->execute($member);
        });
    }
}
