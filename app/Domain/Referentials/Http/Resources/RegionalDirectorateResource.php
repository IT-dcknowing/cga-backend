<?php

namespace App\Domain\Referentials\Http\Resources;

use App\Domain\Referentials\Models\RegionalDirectorate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Directions régionales (F-REF-02), avec les actions permises (bloc can, 02-TRD §4.3) et le statut des données (provisoire, validée : RG-DATA-02).
 *
 * @property RegionalDirectorate $resource
 */
class RegionalDirectorateResource extends JsonResource
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
            'geo_key' => $item->geo_key,
            'active' => $item->active,
            'data_status' => $item->data_status->value,
            'validated_at' => $item->validated_at?->toIso8601ZuluString(),
            'can' => [
                'update' => Gate::allows('update', $item),
                'delete' => Gate::allows('delete', $item),
            ],
        ];
    }
}
