<?php

namespace App\Domain\Obligations\Http\Resources;

use App\Domain\Obligations\Enums\CenterObligationStatus;
use App\Domain\Obligations\Models\CenterObligation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Obligation propre du CGA (F-OBL-07) : libellé, nature, échéance, montant, statut, notes, rappel.
 *
 * @property CenterObligation $resource
 */
class CenterObligationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $obligation = $this->resource;

        return [
            'id' => $obligation->id,
            'label' => $obligation->label,
            'kind' => $obligation->kind->value,
            'kind_label' => $obligation->kind->label(),
            'due_date' => $obligation->due_date->toDateString(),
            'overdue' => ! in_array($obligation->status, [CenterObligationStatus::Done, CenterObligationStatus::Paid], true) && $obligation->due_date->lessThan(CarbonImmutable::today()),
            'amount' => $obligation->amount,
            'status' => $obligation->status->value,
            'status_label' => $obligation->status->label(),
            'notes' => $obligation->notes,
            'reminder_sent_at' => $obligation->reminder_sent_at?->toIso8601ZuluString(),
        ];
    }
}
