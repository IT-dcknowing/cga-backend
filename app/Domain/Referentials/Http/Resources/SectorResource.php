<?php

namespace App\Domain\Referentials\Http\Resources;

use App\Domain\Referentials\Models\Sector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Secteurs d'activité (F-REF-01, RG-REF-01), avec les actions permises (bloc can, 02-TRD §4.3) et le statut des données (provisoire, validée : RG-DATA-02).
 *
 * @property Sector $resource
 */
class SectorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'status' => $item->status->value,
            'status_label' => $item->status->label(),
            'merged_into_id' => $item->merged_into_id,
            'data_status' => $item->data_status->value,
            'validated_at' => $item->validated_at?->toIso8601ZuluString(),
            'can' => [
                'update' => Gate::allows('update', $item),
                'delete' => Gate::allows('delete', $item),
            ],
        ];
    }
}
