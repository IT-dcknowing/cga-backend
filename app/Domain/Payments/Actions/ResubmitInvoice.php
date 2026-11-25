<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Jobs\CertifyInvoice;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * « Corriger et resoumettre » une facture (03-APPFLOW `/cga/factures`, RG-FNE-01, 04) : rejetée, une
 * fois le dossier corrigé (les données du client sont lues à l'envoi) ; ou restée en attente parce que
 * le service était indisponible jusqu'au dernier essai. Elle repart au service sous le même numéro.
 */
class ResubmitInvoice
{
    /**
     * @throws BusinessRuleException invoice_not_resubmittable
     */
    public function execute(Invoice $invoice, Membership $requester): Invoice
    {
        return DB::transaction(function () use ($invoice, $requester): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Rejected && ! $invoice->certificationStalled()) {
                throw new BusinessRuleException('invoice_not_resubmittable');
            }

            $invoice->status = InvoiceStatus::PendingCertification;
            $invoice->rejection_reason = null;
            $invoice->save();

            if ($invoice->certification_mode !== CertificationMode::Manual) {
                CertifyInvoice::dispatch($invoice->id, $requester->id)->afterCommit();
            }

            return $invoice;
        });
    }
}
