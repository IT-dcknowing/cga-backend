<?php

namespace App\Domain\Payments\Http\Resources;

use App\Domain\Payments\Models\MemberCharge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Somme due par l'adhérent (F-PAY-02) : nature, période, échéance, montant, payé, reste, état.
 *
 * @property MemberCharge $resource
 */
class ChargeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $charge = $this->resource;

        return [
            'id' => $charge->id,
            'kind' => $charge->kind->value,
            'kind_label' => $charge->kind->label(),
            'period_start' => $charge->period_start?->toDateString(),
            'period_end' => $charge->period_end?->toDateString(),
            'due_date' => $charge->due_date->toDateString(),
            'amount_excl_tax' => $charge->amount_excl_tax,
            'tax_rate' => $charge->tax_rate,
            'tax_amount' => $charge->tax_amount,
            'amount' => $charge->amount,
            'invoice_id' => $charge->invoice_id,
            'amount_paid' => $charge->amount_paid,
            'balance' => $charge->balance(),
            'status' => $charge->status->value,
            'status_label' => $charge->status->label(),
            'overdue_since' => $charge->overdue_since?->toDateString(),
        ];
    }
}
