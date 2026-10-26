<?php

namespace App\Domain\Payments\Enums;

use App\Support\Enums\HasLabel;

/**
 * Cycle d'une facture (RG-FNE-01) : brouillon, en attente de certification, certifiée (immuable), rejetée avec le motif retourné, annulée avant certification.
 */
enum InvoiceStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case PendingCertification = 'pending_certification';
    case Certified = 'certified';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
