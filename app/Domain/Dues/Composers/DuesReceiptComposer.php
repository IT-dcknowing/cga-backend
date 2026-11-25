<?php

namespace App\Domain\Dues\Composers;

use App\Domain\Documents\Composers\CgaIdentity;
use App\Domain\Documents\Composers\DocumentComposer;
use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Dues\Models\NetworkDuePayment;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\Tenancy;

/**
 * Reçu d'un paiement de cotisation confirmé par le CNCGA (F-COT-03, 04, modèle dues_receipt) : émetteur
 * (le CNCGA, identité de sa fiche), CGA débiteur, année, montant en chiffres et en lettres, date, mode et
 * référence. Le paiement appartient au CGA : il est lu par un contournement nommé.
 */
class DuesReceiptComposer implements DocumentComposer
{
    public function __construct(private readonly CgaIdentity $identity) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $issuer = Organization::query()->with(['logo', 'signature', 'stamp'])->findOrFail($document->organization_id);
        $payment = Tenancy::bypass(static fn (): NetworkDuePayment => NetworkDuePayment::query()->with('due.organization')->findOrFail($document->source_id), 'Reçu de cotisation : paiement du CGA.');
        $debtor = $payment->due->organization;

        return new DocumentContent([
            ...$this->identity->values($issuer),
            'receipt_number' => DocumentFormat::text($payment->receipt_number),
            'debtor_name' => $debtor->name,
            'debtor_agreement' => DocumentFormat::text($debtor->agreement_number),
            'dues_year' => (string) $payment->due->year,
            'payment_amount' => DocumentFormat::amount($payment->amount),
            'payment_amount_words' => DocumentFormat::amountInWords($payment->amount),
            'payment_date' => DocumentFormat::date($payment->paid_on),
            'payment_method' => $payment->method->label(),
            'payment_reference' => DocumentFormat::text($payment->reference),
            'document_date' => DocumentFormat::date($document->issued_at),
        ], $this->identity->images($issuer));
    }
}
