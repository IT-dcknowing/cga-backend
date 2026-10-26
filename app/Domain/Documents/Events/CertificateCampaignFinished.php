<?php

namespace App\Domain\Documents\Events;

use App\Domain\Users\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fin d'une campagne annuelle d'attestations (RG-DOC-06) : nombre d'attestations lancées et de dossiers
 * passés. La notification de fin à celui qui l'a lancée (03-APPFLOW §4) s'y branche avec le moteur de
 * notifications (Lot 6).
 */
class CertificateCampaignFinished
{
    use Dispatchable;

    public function __construct(
        public readonly Membership $requester,
        public readonly int $year,
        public readonly int $produced,
        public readonly int $skipped,
    ) {}
}
