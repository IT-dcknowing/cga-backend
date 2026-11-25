<?php

namespace App\Domain\Obligations\Http\Resources;

use App\Domain\Obligations\Models\ObligationPayment;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paiement d'une échéance (F-OBL-04, RG-OBL-09) : montant, date, origine (CGA, adhérent, import,
 * Compta Flow), état, preuve (lien signé).
 *
 * @property ObligationPayment $resource
 */
class ObligationPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'paid_on' => $payment->paid_on->toDateString(),
            'source' => $payment->source->value,
            'source_label' => $payment->source->label(),
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'confirmed_at' => $payment->confirmed_at?->toIso8601ZuluString(),
            'proof' => $this->whenLoaded('proof', fn (): ?array => $payment->proof === null ? null : [
                'id' => $payment->proof->id,
                'name' => $payment->proof->original_name,
                'url' => app(StorageService::class)->temporaryUrl($payment->proof),
            ]),
        ];
    }
}
