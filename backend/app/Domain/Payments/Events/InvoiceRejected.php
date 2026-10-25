<?php

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Facture rejetée par le service de certification, avec le motif retourné (RG-FNE-01) : la
 * notification « certification rejetée » au gérant (PRD M13) s'y branche au Lot 6.
 */
class InvoiceRejected
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
