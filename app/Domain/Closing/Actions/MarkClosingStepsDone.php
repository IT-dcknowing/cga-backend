<?php

namespace App\Domain\Closing\Actions;

use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Marquer l'étape faite » sur une sélection de dossiers de la campagne (/cga/cloture, actions
 * groupées, F-OBL-08) : même date pour tous ; un dossier dont l'étape est déjà faite ou dont une étape
 * précédente reste à faire est écarté avec son motif (décision 0006), les autres sont marqués. Le dépôt
 * légal ne se marque pas en groupe : chaque dossier a sa référence et son accusé.
 */
class MarkClosingStepsDone
{
    /**
     * @param  list<string>  $itemIds  dossiers de la campagne, déjà contrôlés dans le périmètre
     * @return array{done: list<ClosingCampaignItem>, skipped: list<array{id: string, code: string, message: string}>}
     *
     * @throws BusinessRuleException closing_campaign_closed
     */
    public function execute(ClosingCampaign $campaign, array $itemIds, ClosingStep $step, CarbonImmutable $doneOn): array
    {
        return DB::transaction(function () use ($campaign, $itemIds, $step, $doneOn): array {
            $campaign = ClosingCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($campaign->status === ClosingCampaignStatus::Closed) {
                throw new BusinessRuleException('closing_campaign_closed');
            }

            $done = $skipped = [];
            $items = ClosingCampaignItem::query()->where('campaign_id', $campaign->id)->whereKey($itemIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $item) {
                $refusal = $item->stepRefusal($step);
                if ($refusal !== null) {
                    $skipped[] = ['id' => $item->id, 'code' => $refusal, 'message' => MarkClosingStepDone::refusalMessage($item, $refusal)];

                    continue;
                }
                $item->setAttribute($step->column(), $doneOn);
                $item->save();
                $done[] = $item;
            }

            return ['done' => $done, 'skipped' => $skipped];
        });
    }
}
