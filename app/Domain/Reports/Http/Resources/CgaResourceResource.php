<?php

namespace App\Domain\Reports\Http\Resources;

use App\Domain\Reports\Models\CgaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Moyens matériels d'une catégorie de la fiche permanente du CGA (F-RPT-01).
 *
 * @property CgaResource $resource
 */
class CgaResourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'category' => $resource->category->value,
            'category_label' => $resource->category->label(),
            'quantity' => $resource->quantity,
            'description' => $resource->description,
            'need' => $resource->need,
        ];
    }
}
