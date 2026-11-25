<?php

namespace App\Domain\Reports\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Événement d'échéance d'un rapport trimestriel non transmis (RG-RPT-06, 07), remis au moteur de
 * notifications (Lot 6) : relance à J-15, J-5, J+5 (offset), passage « en retard » au lendemain de J,
 * « hors délai réglementaire » au lendemain de J+15 (alerte du CNCGA). Le rapport peut ne pas encore
 * exister.
 */
abstract class ReportDeadlineEvent
{
    use Dispatchable;

    public function __construct(
        public readonly string $organizationId,
        public readonly int $year,
        public readonly int $quarter,
        public readonly ?int $offset = null,
    ) {}
}
