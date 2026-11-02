<?php

namespace App\Domain\Closing\Enums;

use App\Support\Enums\HasLabel;

/**
 * État d'une campagne de clôture d'un exercice (F-OBL-08).
 */
enum ClosingCampaignStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Closed = 'closed';
}
