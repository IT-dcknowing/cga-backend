<?php

namespace App\Domain\Closing\Actions;

use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Enums\ClosingDeliverable;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suivi d'un dossier dans la campagne de clôture (F-OBL-08) : livrable (SMT ou états financiers,
 * proposé selon le régime et modifiable, décision 0006) et agent qui suit le dossier, choisi parmi les
 * agents du centre.
 */
class UpdateClosingItem
{
    /**
     * @throws BusinessRuleException closing_campaign_closed
     * @throws ValidationException agent hors du centre
     */
    public function execute(ClosingCampaignItem $item, ClosingDeliverable $deliverable, ?string $assignedTo): ClosingCampaignItem
    {
        return DB::transaction(function () use ($item, $deliverable, $assignedTo): ClosingCampaignItem {
            $item = ClosingCampaignItem::query()->with('campaign')->lockForUpdate()->findOrFail($item->id);
            if ($item->campaign->status === ClosingCampaignStatus::Closed) {
                throw new BusinessRuleException('closing_campaign_closed');
            }
            if ($assignedTo !== null && ! $this->isAgent($item->organization_id, $assignedTo)) {
                throw ValidationException::withMessages(['assigned_to' => __('closing.assignee_outside_centre')]);
            }

            $item->deliverable = $deliverable;
            $item->assigned_to = $assignedTo;
            $item->save();

            return $item;
        });
    }

    /** Agent actif du centre : ni accès d'adhérent, ni rattachement suspendu. */
    public function isAgent(string $organizationId, string $userId): bool
    {
        return Membership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereNull('member_id')
            ->where('status', MembershipStatus::Active)
            ->exists();
    }
}
