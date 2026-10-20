<?php

namespace App\Domain\Organizations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Abonnement du CGA à la plateforme, distinct de la cotisation CNCGA (F-ORG-05).
 */
enum SubscriptionStatus: string
{
    use HasLabel;

    case None = 'none';
    case Trial = 'trial';
    case Active = 'active';
    case Terminated = 'terminated';
}
