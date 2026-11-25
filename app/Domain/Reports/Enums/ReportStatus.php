<?php

namespace App\Domain\Reports\Enums;

use App\Support\Enums\HasLabel;

/**
 * Cycle du rapport trimestriel (F-RPT-04) : Brouillon → Généré → Transmis → Accusé reçu.
 */
enum ReportStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Generated = 'generated';
    case Transmitted = 'transmitted';
    case Acknowledged = 'acknowledged';
}
