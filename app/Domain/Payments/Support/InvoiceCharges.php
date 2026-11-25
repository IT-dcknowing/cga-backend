<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\MemberCharge;

/**
 * Sommes dues portées par une facture (F-PAY-05) : une somme due n'est facturée qu'une fois ; elle
 * redevient facturable quand sa facture est annulée avant certification, ou quand un avoir certifié
 * annule sa facture (RG-FNE-01).
 */
class InvoiceCharges
{
    /**
     * Libère les sommes dues de la facture, dans la transaction de l'Action.
     */
    public function release(string $invoiceId): void
    {
        MemberCharge::query()->where('invoice_id', $invoiceId)->lockForUpdate()->get()
            ->each(static function (MemberCharge $charge): void {
                $charge->invoice_id = null;
                $charge->save();
            });
    }

    /**
     * Une facture vient d'être certifiée : un avoir libère les sommes dues de la facture qu'il annule.
     */
    public function certified(Invoice $invoice): void
    {
        if ($invoice->type === InvoiceType::CreditNote && $invoice->original_invoice_id !== null) {
            $this->release($invoice->original_invoice_id);
        }
    }
}
