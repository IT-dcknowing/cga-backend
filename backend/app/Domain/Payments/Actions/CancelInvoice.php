<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Support\InvoiceCharges;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Annulation motivée d'une facture qui n'a jamais été certifiée (F-PAY-05) : rejetée, en attente de
 * la saisie de sa certification en mode manuel, ou restée en attente après le dernier essai d'un
 * service indisponible (RG-FNE-04). Son numéro reste pris (séquence sans trou) et ses
 * sommes dues redeviennent facturables. Une facture certifiée ne s'annule pas : elle se corrige par un
 * avoir (RG-FNE-01) ; une facture en cours d'envoi au service non plus.
 */
class CancelInvoice
{
    public function __construct(private readonly InvoiceCharges $charges) {}

    /**
     * @throws BusinessRuleException invoice_not_cancellable
     */
    public function execute(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $cancellable = in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Rejected], true)
                || ($invoice->status === InvoiceStatus::PendingCertification && $invoice->certification_mode === CertificationMode::Manual)
                || $invoice->certificationStalled();
            if (! $cancellable) {
                throw new BusinessRuleException('invoice_not_cancellable');
            }

            $invoice->status = InvoiceStatus::Cancelled;
            $invoice->cancel_reason = $reason;
            $invoice->save();
            $this->charges->release($invoice->id);

            return $invoice;
        });
    }
}
