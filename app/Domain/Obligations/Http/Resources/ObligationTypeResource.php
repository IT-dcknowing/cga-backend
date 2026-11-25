<?php

namespace App\Domain\Obligations\Http\Resources;

use App\Domain\Obligations\Models\ObligationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Obligation du catalogue (F-OBL-01) : nationale ou locale au centre (RG-OBL-01), avec ses conditions
 * d'applicabilité et sa règle d'échéance ; `can.update` pour une obligation locale du centre.
 *
 * @mixin ObligationType
 */
class ObligationTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ObligationType $type */
        $type = $this->resource;

        return [
            'id' => $type->id,
            'code' => $type->code,
            'label' => $type->label,
            'local' => ! $type->isNational(),
            'catalog_version' => $type->catalog_version,
            'category' => $type->category->value,
            'category_label' => $type->category->label(),
            'authority' => $type->authority,
            'platform' => $type->platform,
            'kind' => $type->kind->value,
            'kind_label' => $type->kind->label(),
            'periodicity' => $type->periodicity->value,
            'periodicity_label' => $type->periodicity->label(),
            'due_rule' => $type->due_rule,
            'applicability' => $type->applicability,
            'tax_nature' => $type->tax_nature?->value,
            'tax_nature_label' => $type->tax_nature?->label(),
            'has_abatement' => $type->has_abatement,
            'creates_payment_on_done' => $type->creates_payment_on_done,
            'active' => $type->active,
            'legal_basis' => $type->legal_basis,
            'data_status' => $type->data_status->value,
            'can' => [
                'update' => Gate::allows('update', $type),
            ],
        ];
    }
}
