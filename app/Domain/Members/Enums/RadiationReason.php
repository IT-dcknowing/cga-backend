<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Motif d'une radiation (F-ADH-06), selon l'initiateur (config/radiations.php).
 */
enum RadiationReason: string
{
    use HasLabel;

    case ZoneChange = 'zone_change';
    case FollowUpImpossible = 'follow_up_impossible';
    case SeriousBreach = 'serious_breach';
    case ReclassifiedRealRegime = 'reclassified_real_regime';
    case Cessation = 'cessation';
    case BusinessSale = 'business_sale';
    case Death = 'death';
    case Dissatisfaction = 'dissatisfaction';
    case FirmChange = 'firm_change';
    case MutualTermination = 'mutual_termination';
    case AgreementLoss = 'agreement_loss';
    case Other = 'other';
}
