<?php

namespace App\Domain\Closing\Events;

use App\Domain\Closing\Models\ClosingCampaign;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dossiers de la campagne en retard sur une étape, à signaler aux agents du CGA (RG-OBL-11), par
 * étape. L'envoi est branché par le moteur de notifications (Lot 6).
 */
final class ClosingStepsLate
{
    use Dispatchable;

    /**
     * @param  array<string, int>  $late  code de l'étape => dossiers en retard
     */
    public function __construct(
        public readonly ClosingCampaign $campaign,
        public readonly array $late,
    ) {}
}
