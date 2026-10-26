<?php

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Facture ou avoir certifié FNE (RG-FNE-01, 02) : la notification « Facture certifiée FNE disponible »
 * au client et au gérant (PRD M13) s'y branche avec le moteur de notifications (Lot 6).
 */
class InvoiceCertified
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
