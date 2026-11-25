<?php

namespace App\Domain\Payments\Http\Resources;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Paiement d'un adhérent (F-PAY-02, 03) : montant, date, mode, origine, état et motif de rejet, n° de
 * reçu, preuve (lien signé), ventilation sur les sommes dues, actions permises.
 *
 * @property MemberPayment $resource
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;
        $pending = $payment->status === PaymentStatus::PendingConfirmation;
        $reviewable = $pending && Gate::allows('review', $payment);

        return [
            'id' => $payment->id,
            'member' => $this->whenLoaded('member', fn (): array => ['id' => $payment->member->id, 'company_name' => $payment->member->company_name]),
            'amount' => $payment->amount,
            'paid_on' => $payment->paid_on->toDateString(),
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'reference' => $payment->reference,
            'source' => $payment->source->value,
            'source_label' => $payment->source->label(),
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'rejection_reason' => $payment->rejection_reason,
            'receipt_number' => $payment->receipt_number,
            'confirmed_at' => $payment->confirmed_at?->toIso8601ZuluString(),
            'proof' => $this->whenLoaded('proof', fn (): ?array => $payment->proof === null ? null : [
                'id' => $payment->proof->id,
                'name' => $payment->proof->original_name,
                'url' => app(StorageService::class)->temporaryUrl($payment->proof),
            ]),
            'allocations' => $this->whenLoaded('allocations', fn (): array => $payment->allocations->map(static fn (PaymentAllocation $allocation): array => [
                'charge_id' => $allocation->charge_id,
                'amount' => $allocation->amount,
            ])->all()),
            'created_at' => $payment->created_at?->toIso8601ZuluString(),
            'can' => [
                'confirm' => $reviewable,
                'reject' => $reviewable,
            ],
        ];
    }
}
