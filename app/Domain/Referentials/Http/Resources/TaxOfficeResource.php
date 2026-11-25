<?php

namespace App\Domain\Referentials\Http\Resources;

use App\Domain\Referentials\Models\TaxOffice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Centres des impôts (F-REF-02), avec les actions permises (bloc can, 02-TRD §4.3) et le statut des données (provisoire, validée : RG-DATA-02).
 *
 * @property TaxOffice $resource
 */
class TaxOfficeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            'regional_directorate' => [
                'id' => $item->regionalDirectorate->id,
                'code' => $item->regionalDirectorate->code,
                'name' => $item->regionalDirectorate->name,
            ],
            'code' => $item->code,
            'name' => $item->name,
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
