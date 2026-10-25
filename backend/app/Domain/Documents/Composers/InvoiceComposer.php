<?php

namespace App\Domain\Documents\Composers;

use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Models\Invoice;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Contracts\Config\Repository;

/**
 * Données d'une facture ou d'un avoir certifié FNE (F-PAY-05, RG-FNE-02, modèles invoice et
 * credit_note) : n° FAC, client (n° d'adhésion, ou n° client au portefeuille Cabinet), une ligne par
 * somme due (quantité, prix unitaire hors taxe, taux de TVA, montant hors taxe), totaux hors taxe, TVA
 * et toutes taxes comprises, montant en lettres ; éléments renvoyés par le service de certification :
 * n° et date de certification, QR de certification, cachet fiscal électronique. Un avoir rappelle la
 * facture qu'il annule.
 */
class InvoiceComposer implements DocumentComposer
{
    public function __construct(
        private readonly CgaIdentity $identity,
        private readonly Repository $config,
    ) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $organization = Organization::query()->with(['logo', 'signature', 'stamp'])->findOrFail($document->organization_id);
        $invoice = Invoice::query()->with(['member', 'original'])->findOrFail($document->source_id);
        $member = $invoice->member;

        $lines = array_map(static fn (array $line): array => [
            'line_label' => $line['label'],
            'line_quantity' => (string) $line['quantity'],
            'line_unit_amount' => DocumentFormat::amount($line['unit_amount']),
            'line_tax_rate' => DocumentFormat::rate($line['tax_rate']),
            'line_amount' => DocumentFormat::amount($line['amount']),
        ], $invoice->lines);

        return new DocumentContent([
            ...$this->identity->values($organization),
            'invoice_title' => (string) __('payments.invoice_titles.'.$invoice->type->value),
            'invoice_number' => $invoice->number,
            'invoice_date' => DocumentFormat::date($invoice->issue_date),
            'invoice_reference' => $invoice->type === InvoiceType::CreditNote && $invoice->original !== null
                ? (string) __('payments.credit_note_reference', ['number' => $invoice->original->number, 'date' => DocumentFormat::date($invoice->original->issue_date)])
                : '',
            'member_name' => DocumentFormat::text($member?->company_name),
            'member_ncc' => DocumentFormat::text($member?->ncc),
            'member_number' => DocumentFormat::text($member->membership_number ?? $member?->client_number),
            'member_address' => DocumentFormat::text($member?->address),
            'amount_excl_tax' => DocumentFormat::amount($invoice->amount_excl_tax),
            'tax_amount' => DocumentFormat::amount($invoice->tax_amount),
            'amount_incl_tax' => DocumentFormat::amount($invoice->amount_incl_tax),
            'amount_words' => DocumentFormat::amountInWords($invoice->amount_incl_tax),
            'fne_number' => DocumentFormat::text($invoice->fne_number),
            'fne_certified_at' => DocumentFormat::dateTime($invoice->certified_at),
            'fne_stamp' => DocumentFormat::text($invoice->fne_stamp),
            'document_date' => DocumentFormat::date($document->issued_at),
        ], [
            ...$this->identity->images($organization),
            'fne_qr' => $this->certificationQr((string) $invoice->fne_qr_payload),
        ], [
            'line_label' => $lines,
        ]);
    }

    /**
     * QR de certification renvoyé par le service FNE (RG-FNE-02), en image PNG.
     */
    private function certificationQr(string $payload): string
    {
        $qr = (array) $this->config->get('documents.qr');

        return (new PngWriter)->write(new QrCode(data: $payload, size: (int) ($qr['size'] ?? 240), margin: (int) ($qr['margin'] ?? 8)))->getString();
    }
}
