<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Décision du CGA sur les obligations d'un dossier (F-OBL-02, RG-OBL-02) : confirmer des propositions,
 * retirer des obligations proposées ou confirmées. Une obligation retirée garde son historique ; ses
 * échéances à venir non commencées sont annulées, les échéances passées ne changent pas.
 */
class ReviewMemberObligations
{
    /**
     * @param  list<string>  $confirm  obligations proposées à confirmer
     * @param  list<string>  $remove  obligations à retirer
     * @return array{confirmed: int, removed: int}
     *
     * @throws BusinessRuleException obligation_not_reviewable
     * @throws ValidationException une obligation à la fois confirmée et retirée
     */
    public function execute(Member $member, array $confirm, array $remove, User $actor): array
    {
        if (array_intersect($confirm, $remove) !== []) {
            throw ValidationException::withMessages(['remove' => __('obligations.review_overlap')]);
        }

        return DB::transaction(function () use ($member, $confirm, $remove, $actor): array {
            $obligations = MemberObligation::query()
                ->where('member_id', $member->id)
                ->whereIn('id', [...$confirm, ...$remove])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($confirm as $id) {
                $obligation = $obligations->get($id);
                if ($obligation === null || $obligation->status !== MemberObligationStatus::Suggested) {
                    throw new BusinessRuleException('obligation_not_reviewable');
                }

                $obligation->status = MemberObligationStatus::Active;
                $obligation->confirmed_by = $actor->id;
                $obligation->confirmed_at = now()->toImmutable();
                $obligation->save();
            }

            foreach ($remove as $id) {
                $obligation = $obligations->get($id);
                if ($obligation === null || $obligation->status === MemberObligationStatus::Removed) {
                    throw new BusinessRuleException('obligation_not_reviewable');
                }

                $obligation->status = MemberObligationStatus::Removed;
                $today = today()->toImmutable();
                $obligation->end_date = $today->lessThan($obligation->start_date) ? $obligation->start_date : $today;
                $obligation->save();
                $this->cancelUpcoming($obligation);
            }

            return ['confirmed' => count($confirm), 'removed' => count($remove)];
        });
    }

    /**
     * Échéances à venir d'une obligation retirée, encore à faire : annulées (RG-DATA-04).
     */
    private function cancelUpcoming(MemberObligation $obligation): void
    {
        ObligationInstance::query()
            ->where('member_obligation_id', $obligation->id)
            ->where('due_date', '>=', today())
            ->whereIn('status', [InstanceStatus::Todo, InstanceStatus::ToPay, InstanceStatus::NotEstablished])
            ->where('amount_paid', 0)
            ->get()
            ->each(static function (ObligationInstance $instance): void {
                $instance->status = InstanceStatus::Cancelled;
                $instance->save();
            });
    }
}
