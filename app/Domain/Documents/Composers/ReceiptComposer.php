<?php

namespace App\Domain\Documents\Composers;

use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Payments\Support\ChargeLabel;

/**
 * Données du reçu d'un paiement confirmé (F-PAY-02, RG-PAY-02, modèle receipt) : n° du reçu, adhérent
 * (n° d'adhésion, ou n° client au portefeuille Cabinet), montant en chiffres et en lettres, date, mode
 * et référence du paiement, une ligne par somme réglée (droit d'adhésion, période d'honoraires,
 * formation).
 */
class ReceiptComposer implements DocumentComposer
{
    public function __construct(private readonly CgaIdentity $identity) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $organization = Organization::query()->with(['logo', 'signature', 'stamp'])->findOrFail($document->organization_id);
        $payment = MemberPayment::query()->with(['member', 'allocations.charge'])->findOrFail($document->source_id);

        $lines = $payment->allocations->map(static fn (PaymentAllocation $allocation): array => [
            'line_label' => ChargeLabel::of($allocation->charge),
            'line_amount' => DocumentFormat::amount($allocation->amount),
        ])->values()->all();

        return new DocumentContent([
            ...$this->identity->values($organization),
            'receipt_number' => DocumentFormat::text($document->number),
            'member_name' => $payment->member->company_name,
            'member_ncc' => $payment->member->ncc,
            'member_number' => DocumentFormat::text($payment->member->membership_number ?? $payment->member->client_number),
            'payment_amount' => DocumentFormat::amount($payment->amount),
            'payment_amount_words' => DocumentFormat::amountInWords($payment->amount),
            'payment_date' => DocumentFormat::date($payment->paid_on),
            'payment_method' => $payment->method->label(),
            'payment_reference' => DocumentFormat::text($payment->reference),
            'document_date' => DocumentFormat::date($document->issued_at),
        ], $this->identity->images($organization), [
            'line_label' => $lines !== [] ? $lines : [['line_label' => DocumentFormat::text(null), 'line_amount' => DocumentFormat::amount($payment->amount)]],
        ]);
    }
}
