<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Support\DocumentNumbering;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Fne\FneCertifiers;
use App\Domain\Payments\Jobs\CertifyInvoice;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Support\InvoicingMode;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * « Émettre un avoir » sur une facture certifiée (RG-FNE-01) : l'avoir annule toute la facture (avoir
 * total, décision du 23/10/2026) — mêmes lignes et mêmes montants, n° FAC propre, référence de la
 * facture d'origine — et suit le même cycle de certification. Une fois certifié, il libère les
 * sommes dues de la facture, qui se refacturent si besoin. Un seul avoir par facture ; la facture
 * d'origine reste certifiée et inchangée.
 */
class IssueCreditNote
{
    public function __construct(
        private readonly DocumentNumbering $numbering,
        private readonly InvoicingMode $modes,
        private readonly FneCertifiers $certifiers,
    ) {}

    /**
     * @throws BusinessRuleException credit_note_unavailable, credit_note_exists, certification_mode_unavailable
     */
    public function execute(Invoice $original, Membership $requester): Invoice
    {
        return DB::transaction(function () use ($original, $requester): Invoice {
            $original = Invoice::query()->lockForUpdate()->findOrFail($original->id);
            if ($original->type !== InvoiceType::Invoice || $original->status !== InvoiceStatus::Certified) {
                throw new BusinessRuleException('credit_note_unavailable');
            }
            if (Invoice::query()->where('original_invoice_id', $original->id)->where('status', '<>', InvoiceStatus::Cancelled)->exists()) {
                throw new BusinessRuleException('credit_note_exists');
            }
            $mode = $this->modes->current($original->organization_id);
            if (! $this->certifiers->available($mode)) {
                throw new BusinessRuleException('certification_mode_unavailable', details: ['mode' => $mode->value]);
            }

            $creditNote = new Invoice;
            $creditNote->organization_id = $original->organization_id;
            $creditNote->type = InvoiceType::CreditNote;
            $creditNote->original_invoice_id = $original->id;
            $creditNote->member_id = $original->member_id;
            $creditNote->billed_organization_id = $original->billed_organization_id;
            $creditNote->number = $this->numbering->next($original->organization_id, DocumentType::CreditNote);
            $creditNote->issue_date = today()->toImmutable();
            $creditNote->lines = $original->lines;
            $creditNote->amount_excl_tax = $original->amount_excl_tax;
            $creditNote->tax_amount = $original->tax_amount;
            $creditNote->amount_incl_tax = $original->amount_incl_tax;
            $creditNote->status = InvoiceStatus::PendingCertification;
            $creditNote->certification_mode = $mode;
            $creditNote->created_by = $requester->user_id;
            $creditNote->save();

            if ($mode !== CertificationMode::Manual) {
                CertifyInvoice::dispatch($creditNote->id, $requester->id)->afterCommit();
            }

            return $creditNote;
        });
    }
}
