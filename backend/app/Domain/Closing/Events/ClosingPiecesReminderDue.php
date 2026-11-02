<?php

namespace App\Domain\Closing\Events;

use App\Domain\Closing\Models\ClosingCampaignItem;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Rappel de pièces de clôture dû aujourd'hui au client (RG-OBL-11) : avant l'étape 1 ou en retard.
 * L'envoi (SMS, WhatsApp, e-mail) est branché par le moteur de notifications (Lot 6).
 */
final class ClosingPiecesReminderDue
{
    use Dispatchable;

    /**
     * @param  'before'|'late'  $reason
     */
    public function __construct(
        public readonly ClosingCampaignItem $item,
        public readonly string $reason,
    ) {}
}
