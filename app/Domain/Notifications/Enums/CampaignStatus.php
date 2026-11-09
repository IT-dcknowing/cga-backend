<?php

namespace App\Domain\Notifications\Enums;

use App\Support\Enums\HasLabel;

/**
 * Cycle d'un envoi groupé (F-COM-03) : brouillon, en attente de validation du gérant (RG-COM-01),
 * validé, en cours d'envoi, envoyé, refusé.
 */
enum CampaignStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sending = 'sending';
    case Sent = 'sent';
    case Rejected = 'rejected';
}
