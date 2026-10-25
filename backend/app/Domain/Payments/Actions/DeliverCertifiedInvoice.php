<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Documents\Actions\IssueDocument;
use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Events\DocumentReady;
use App\Domain\Documents\Models\File;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Events\InvoiceCertified;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Remise d'une facture certifiée (RG-FNE-01, 02) : le PDF remis au client est produit par la chaîne
 * documentaire avec les éléments de certification (mode API), ou c'est la facture certifiée déposée
 * (mode manuel, 02-TRD §8.5) ; il rejoint les documents de l'adhérent. Une facture certifiée dont le
 * PDF n'a pas pu être produit (fiche du centre incomplète, modèle absent) se remet ensuite à la demande.
 */
class DeliverCertifiedInvoice
{
    public function __construct(private readonly IssueDocument $issue) {}

    /**
     * @param  File|null  $certifiedPdf  facture certifiée déposée en mode manuel ; sinon le PDF est produit
     *
     * @throws BusinessRuleException invoice_not_deliverable, cga_profile_incomplete, template_missing
     */
    public function execute(Invoice $invoice, Membership $requester, ?File $certifiedPdf = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $requester, $certifiedPdf): Invoice {
            $invoice = Invoice::query()->with('member')->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Certified || $invoice->document_id !== null) {
                throw new BusinessRuleException('invoice_not_deliverable');
            }

            $type = $invoice->type === InvoiceType::CreditNote ? DocumentType::CreditNote : DocumentType::Invoice;
            $title = (string) __('documents.title', ['type' => $type->label(), 'member' => $invoice->member->company_name ?? $invoice->number]);

            $document = $certifiedPdf === null
                ? $this->issue->execute(new DocumentRequest(
                    type: $type,
                    title: $title,
                    memberId: $invoice->member_id,
                    number: $invoice->number,
                    sourceType: 'invoice',
                    sourceId: $invoice->id,
                ), $requester)
                : $this->deposited($invoice, $type, $title, $certifiedPdf, $requester);

            $invoice->document_id = $document->id;
            $invoice->save();

            InvoiceCertified::dispatch($invoice);

            return $invoice;
        });
    }

    private function deposited(Invoice $invoice, DocumentType $type, string $title, File $pdf, Membership $requester): GeneratedDocument
    {
        $document = new GeneratedDocument;
        $document->organization_id = $invoice->organization_id;
        $document->member_id = $invoice->member_id;
        $document->type = $type;
        $document->title = $title;
        $document->number = $invoice->number;
        $document->version = 1;
        $document->pdf_file_id = $pdf->id;
        $document->sha256 = $pdf->sha256;
        $document->status = DocumentStatus::Valid;
        $document->issued_at = now()->toImmutable();
        $document->visible_to_member = true;
        $document->source_type = 'invoice';
        $document->source_id = $invoice->id;
        $document->generated_by = $requester->user_id;
        $document->save();

        DocumentReady::dispatch($document);

        return $document;
    }
}
