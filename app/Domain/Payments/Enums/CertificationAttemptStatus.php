<?php

namespace App\Domain\Payments\Enums;

use App\Support\Enums\HasLabel;

/**
 * Issue d'un échange avec le service de certification (RG-FNE-04) : envoyé, accepté, rejeté, en erreur (service indisponible).
 */
enum CertificationAttemptStatus: string
{
    use HasLabel;

    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Error = 'error';
}
