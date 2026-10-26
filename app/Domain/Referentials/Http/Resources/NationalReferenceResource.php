<?php

namespace App\Domain\Referentials\Http\Resources;

use App\Domain\Referentials\Models\NationalReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Références nationales annuelles (F-REF-04), avec les actions permises (bloc can, 02-TRD §4.3) et le statut des données (provisoire, validée : RG-DATA-02).
 *
 * @property NationalReference $resource
 */
class NationalReferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            'year' => $item->year,
            'domestic_tax_revenue' => $item->domestic_tax_revenue,
            'nominal_gdp' => $item->nominal_gdp,
            'tax_revenue_source' => $item->tax_revenue_source,
            'gdp_source' => $item->gdp_source,
            'data_status' => $item->data_status->value,
            'validated_at' => $item->validated_at?->toIso8601ZuluString(),
            'can' => [
                'update' => Gate::allows('update', $item),
                'delete' => Gate::allows('delete', $item),
            ],
        ];
    }
}
