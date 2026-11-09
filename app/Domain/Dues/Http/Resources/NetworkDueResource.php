<?php

namespace App\Domain\Dues\Http\Resources;

use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Dues\Models\NetworkDuePayment;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cotisation d'un CGA (F-COT-02, F-COT-06) : montant de l'année, prorata, dû, réglé, reste, date butoir,
 * statut (RG-COT-04, « en attente de confirmation » quand un paiement déclaré attend), relances ; en
 * détail, ses paiements avec preuve et reçu téléchargeables.
 *
 * @property NetworkDue $resource
 */
class NetworkDueResource extends JsonResource
{
    private bool $withPayments = false;

    private bool $debtor = false;

    public function withPayments(): self
    {
        $this->withPayments = true;

        return $this;
    }

    /**
     * Vue du centre débiteur : sa facture de cotisation ne lui est remise qu'une fois certifiée (RG-FNE-01).
     */
    public function forDebtor(): self
    {
        $this->debtor = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $due = $this->resource;
        $pending = $due->relationLoaded('payments')
            ? $due->payments->contains(static fn (NetworkDuePayment $payment): bool => $payment->status === PaymentStatus::PendingConfirmation)
            : null;

        $data = [
            'id' => $due->id,
            'cga' => $due->relationLoaded('organization') ? ['id' => $due->organization->id, 'name' => $due->organization->name, 'sigle' => $due->organization->sigle] : null,
            'year' => $due->year,
            'base_amount' => $due->base_amount,
            'prorata_ratio' => (float) $due->prorata_ratio,
            'amount_due' => $due->amount_due,
            'amount_paid' => $due->amount_paid,
            'balance' => $due->balance(),
            'deadline' => $due->deadline->toDateString(),
            'status' => $due->status->value,
            'status_label' => $due->status->label(),
            'pending_confirmation' => $pending,
            'last_reminder_at' => $due->last_reminder_at?->toIso8601ZuluString(),
            'reminder_count' => $due->reminder_count,
            // Facture de cotisation (étape 6.9) : remise au centre une fois certifiée seulement (RG-FNE-01).
            'invoice' => ! $due->relationLoaded('invoice') || $due->invoice === null || ($this->debtor && $due->invoice->status !== InvoiceStatus::Certified) ? null : [
                'id' => $due->invoice->id,
                'number' => $due->invoice->number,
                'status' => $due->invoice->status->value,
                'status_label' => $due->invoice->status->label(),
                'pdf_url' => $due->invoice->document?->pdf === null ? null : app(StorageService::class)->temporaryUrl($due->invoice->document->pdf),
            ],
        ];
        if (! $this->withPayments) {
            return $data;
        }
        $storage = app(StorageService::class);

        return [...$data, 'payments' => $due->payments->sortByDesc(static fn (NetworkDuePayment $payment): string => $payment->paid_on->format('Ymd').$payment->id)->values()->map(static fn (NetworkDuePayment $payment): array => [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'paid_on' => $payment->paid_on->toDateString(),
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'reference' => $payment->reference,
            'source' => $payment->source->value,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'rejection_reason' => $payment->rejection_reason,
            'receipt_number' => $payment->receipt_number,
            'proof_url' => $payment->proof === null ? null : $storage->temporaryUrl($payment->proof),
            'receipt_url' => $payment->receipt?->pdf === null ? null : $storage->temporaryUrl($payment->receipt->pdf),
        ])->all()];
    }
}
