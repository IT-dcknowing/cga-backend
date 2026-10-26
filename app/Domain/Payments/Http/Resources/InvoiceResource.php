<?php

namespace App\Domain\Payments\Http\Resources;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Http\Resources\DocumentResource;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Models\FneCertification;
use App\Domain\Payments\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Facture ou avoir vu par le CGA (F-PAY-05, F-PAY-06, 03-APPFLOW `/cga/factures`) : n°, nature, client,
 * lignes et montants, statut FNE (brouillon, en attente, certifiée, rejetée avec le motif, annulée),
 * éléments de certification, essais auprès du service, facture d'origine ou avoir, PDF remis — lien
 * signé seulement une fois certifiée (RG-FNE-01) — et actions permises.
 *
 * @property Invoice $resource
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invoice = $this->resource;
        $manage = Gate::allows('manage', $invoice);
        $certified = $invoice->status === InvoiceStatus::Certified;
        $manual = $invoice->certification_mode === CertificationMode::Manual;
        $stalled = $manage && $invoice->certificationStalled();

        return [
            'id' => $invoice->id,
            'type' => $invoice->type->value,
            'type_label' => $invoice->type->label(),
            'number' => $invoice->number,
            'issue_date' => $invoice->issue_date->toDateString(),
            'member' => $this->whenLoaded('member', fn (): ?array => $invoice->member === null ? null : [
                'id' => $invoice->member->id,
                'company_name' => $invoice->member->company_name,
                'number' => $invoice->member->membership_number ?? $invoice->member->client_number,
            ]),
            'original' => $this->whenLoaded('original', fn (): ?array => $invoice->original === null ? null : ['id' => $invoice->original->id, 'number' => $invoice->original->number]),
            'credit_note' => $this->whenLoaded('creditNote', fn (): ?array => $invoice->creditNote === null ? null : [
                'id' => $invoice->creditNote->id,
                'number' => $invoice->creditNote->number,
                'status' => $invoice->creditNote->status->value,
            ]),
            'lines' => $invoice->lines,
            'amount_excl_tax' => $invoice->amount_excl_tax,
            'tax_amount' => $invoice->tax_amount,
            'amount_incl_tax' => $invoice->amount_incl_tax,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'certification_mode' => $invoice->certification_mode->value,
            'certification_mode_label' => $invoice->certification_mode->label(),
            'fne_number' => $invoice->fne_number,
            'fne_stamp' => $invoice->fne_stamp,
            'certified_at' => $invoice->certified_at?->toIso8601ZuluString(),
            'rejection_reason' => $invoice->rejection_reason,
            'cancel_reason' => $invoice->cancel_reason,
            'certifications' => $this->whenLoaded('certifications', fn (): array => $invoice->certifications->map(static fn (FneCertification $attempt): array => [
                'attempt' => $attempt->attempt,
                'status' => $attempt->status->value,
                'status_label' => $attempt->status->label(),
                'requested_at' => $attempt->requested_at->toIso8601ZuluString(),
                'responded_at' => $attempt->responded_at?->toIso8601ZuluString(),
                'message' => $attempt->message,
                'next_retry_at' => $attempt->next_retry_at?->toIso8601ZuluString(),
            ])->values()->all()),
            'pdf' => $this->whenLoaded('document', fn (): ?array => $certified && $invoice->document !== null && $invoice->document->status === DocumentStatus::Valid
                ? DocumentResource::file($invoice->document->pdf)
                : null),
            'created_at' => $invoice->created_at?->toIso8601ZuluString(),
            'can' => [
                'certify_manually' => $manage && $manual && $invoice->status === InvoiceStatus::PendingCertification,
                'resubmit' => $manage && ($invoice->status === InvoiceStatus::Rejected || $stalled),
                'cancel' => $manage && ($invoice->status === InvoiceStatus::Rejected || ($manual && $invoice->status === InvoiceStatus::PendingCertification) || $stalled),
                'issue_credit_note' => $manage && $certified && $invoice->type === InvoiceType::Invoice && $invoice->relationLoaded('creditNote') && $invoice->creditNote === null,
                'deliver' => $manage && $certified && $invoice->document_id === null,
            ],
        ];
    }
}
