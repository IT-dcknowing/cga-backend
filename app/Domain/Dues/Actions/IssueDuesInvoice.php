<?php

namespace App\Domain\Dues\Actions;

use App\Domain\Documents\Actions\IssueDocument;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Support\DocumentNumbering;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Fne\FneCertifiers;
use App\Domain\Payments\Jobs\CertifyInvoice;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Support\InvoicingMode;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * « Facturer » une cotisation (étape 6.9, F-PAY-06, RG-FNE-01 à 05) : le CNCGA émet au CGA débiteur une
 * facture de son montant dû (une seule par cotisation), soumise à la certification FNE selon le mode du
 * CNCGA ; elle n'est remise au CGA qu'une fois certifiée, et ne se modifie plus (avoir). Aucune
 * conséquence sur les droits du centre : pas de suspension automatique (RG-COT-06).
 */
class IssueDuesInvoice
{
    public function __construct(
        private readonly IssueDocument $documents,
        private readonly DocumentNumbering $numbering,
        private readonly InvoicingMode $modes,
        private readonly FneCertifiers $certifiers,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @throws BusinessRuleException dues_already_invoiced, cga_profile_incomplete, certification_mode_unavailable, abbreviation_missing
     */
    public function execute(NetworkDue $due, Membership $requester): Invoice
    {
        return DB::transaction(function () use ($due, $requester): Invoice {
            $due = Tenancy::bypass(static fn (): NetworkDue => NetworkDue::query()->with('organization')->lockForUpdate()->findOrFail($due->id), 'Cotisation : facturation par le CNCGA.');
            if ($due->invoice_id !== null) {
                throw new BusinessRuleException('dues_already_invoiced');
            }
            $issuer = $requester->organization_id;
            $this->documents->ensureProfileComplete($issuer);
            $mode = $this->modes->current($issuer);
            if (! $this->certifiers->available($mode)) {
                throw new BusinessRuleException('certification_mode_unavailable', details: ['mode' => $mode->value]);
            }

            $rate = (float) $this->settings->get('dues.tax_rate');
            $excl = $due->amount_due;
            $tax = (int) round($excl * $rate / 100);
            $invoice = new Invoice;
            $invoice->organization_id = $issuer;
            $invoice->type = InvoiceType::Invoice;
            $invoice->billed_organization_id = $due->organization_id;
            $invoice->number = $this->numbering->next($issuer, DocumentType::Invoice);
            $invoice->issue_date = today()->toImmutable();
            $invoice->lines = [[
                'charge_id' => null,
                'label' => (string) __('dues.invoice_line', ['year' => $due->year, 'cga' => $due->organization->name]),
                'quantity' => 1,
                'unit_amount' => $excl,
                'tax_rate' => $rate,
                'amount' => $excl,
            ]];
            $invoice->amount_excl_tax = $excl;
            $invoice->tax_amount = $tax;
            $invoice->amount_incl_tax = $excl + $tax;
            $invoice->status = InvoiceStatus::PendingCertification;
            $invoice->certification_mode = $mode;
            $invoice->created_by = $requester->user_id;
            $invoice->save();

            $due->invoice_id = $invoice->id;
            $due->save();

            if ($mode !== CertificationMode::Manual) {
                CertifyInvoice::dispatch($invoice->id, $requester->id)->afterCommit();
            }

            return $invoice;
        });
    }
}
