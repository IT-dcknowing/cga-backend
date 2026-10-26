<?php

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Service de certification toujours indisponible après le dernier essai (RG-FNE-04) : la facture reste
 * en attente ; l'alerte au gérant s'y branche avec le moteur de notifications (Lot 6).
 */
class InvoiceCertificationFailed
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
