<?php

namespace App\Domain\Referentials\Http\Resources;

use App\Domain\Referentials\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Jours fériés (RG-OBL-06), avec les actions permises (bloc can, 02-TRD §4.3).
 *
 * @property Holiday $resource
 */
class HolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            'date' => $item->date->toDateString(),
            'label' => $item->label,
            'can' => [
                'update' => Gate::allows('update', $item),
                'delete' => Gate::allows('delete', $item),
            ],
        ];
    }
}
