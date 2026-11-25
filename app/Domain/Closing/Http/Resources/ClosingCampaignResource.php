<?php

namespace App\Domain\Closing\Http\Resources;

use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Models\ClosingCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Campagne de clôture d'un exercice (F-OBL-08) : calendrier figé des 4 étapes (RG-OBL-10), état.
 *
 * @property ClosingCampaign $resource
 */
class ClosingCampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $campaign = $this->resource;

        return [
            'id' => $campaign->id,
            'fiscal_year' => $campaign->fiscal_year,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'opened_at' => $campaign->opened_at->toIso8601ZuluString(),
            'closed_at' => $campaign->closed_at?->toIso8601ZuluString(),
            'steps' => array_map(static fn (ClosingStep $step): array => [
                'code' => $step->value,
                'label' => $step->label(),
                'due' => $campaign->due($step)->toDateString(),
            ], ClosingStep::cases()),
        ];
    }
}
