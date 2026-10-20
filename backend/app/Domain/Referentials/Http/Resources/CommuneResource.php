<?php

namespace App\Domain\Referentials\Http\Resources;

use App\Domain\Referentials\Models\Commune;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Communes (F-REF-02), avec les actions permises (bloc can, 02-TRD §4.3) et le statut des données (provisoire, validée : RG-DATA-02).
 *
 * @property Commune $resource
 */
class CommuneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            'name' => $item->name,
            'regional_directorate_id' => $item->regional_directorate_id,
            'data_status' => $item->data_status->value,
            'validated_at' => $item->validated_at?->toIso8601ZuluString(),
            'can' => [
                'update' => Gate::allows('update', $item),
                'delete' => Gate::allows('delete', $item),
            ],
        ];
    }
}
