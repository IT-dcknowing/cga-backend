<?php

namespace App\Domain\Organizations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Événement de l'historique d'un CGA (05-BACKEND-SCHEMA §3).
 */
enum OrganizationStatusEvent: string
{
    use HasLabel;

    case Suspended = 'suspended';
    case Reactivated = 'reactivated';
    case AgreementWithdrawn = 'agreement_withdrawn';
    case SubscriptionChanged = 'subscription_changed';
    case Terminated = 'terminated';
}
