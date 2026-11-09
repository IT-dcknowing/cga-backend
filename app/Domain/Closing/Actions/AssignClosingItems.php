<?php

namespace App\Domain\Closing\Actions;

use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Affecter à un agent » une sélection de dossiers de la campagne (/cga/cloture, actions groupées,
 * F-OBL-08) : l'agent est choisi parmi les agents actifs du centre ; aucun agent : null.
 */
class AssignClosingItems
{
    public function __construct(private readonly UpdateClosingItem $update) {}

    /**
     * @param  list<string>  $itemIds  dossiers de la campagne, déjà contrôlés dans le périmètre
     * @return list<ClosingCampaignItem>
     *
     * @throws BusinessRuleException closing_campaign_closed
     * @throws ValidationException agent hors du centre
     */
    public function execute(ClosingCampaign $campaign, array $itemIds, ?string $assignedTo): array
    {
        return DB::transaction(function () use ($campaign, $itemIds, $assignedTo): array {
            $campaign = ClosingCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($campaign->status === ClosingCampaignStatus::Closed) {
                throw new BusinessRuleException('closing_campaign_closed');
            }
            if ($assignedTo !== null && ! $this->update->isAgent($campaign->organization_id, $assignedTo)) {
                throw ValidationException::withMessages(['assigned_to' => __('closing.assignee_outside_centre')]);
            }

            $items = ClosingCampaignItem::query()->where('campaign_id', $campaign->id)->whereKey($itemIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $item) {
                $item->assigned_to = $assignedTo;
                $item->save();
            }

            return array_values($items->all());
        });
    }
}
