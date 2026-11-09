<?php

namespace App\Domain\Reports\Support;

use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\QuarterlyReport;
use Carbon\CarbonImmutable;

/**
 * État de transmission d'un rapport vu du réseau (RG-RPT-04, RG-RPT-07, /cncga/rapports) : accusé reçu,
 * transmis, hors délai réglementaire (après J+15), en retard (après J), non transmis. Calculé aussi
 * pour un CGA qui n'a pas encore préparé son rapport.
 */
final class NetworkReportStatus
{
    public const ACKNOWLEDGED = 'acknowledged';

    public const TRANSMITTED = 'transmitted';

    public const LEGALLY_LATE = 'legally_late';

    public const LATE = 'late';

    public const NOT_TRANSMITTED = 'not_transmitted';

    public static function of(?QuarterlyReport $report, CarbonImmutable $deadline, CarbonImmutable $legalDeadline, CarbonImmutable $today): string
    {
        if ($report !== null && $report->transmitted_at !== null) {
            return $report->status === ReportStatus::Acknowledged ? self::ACKNOWLEDGED : self::TRANSMITTED;
        }

        return match (true) {
            $today->greaterThan($legalDeadline) => self::LEGALLY_LATE,
            $today->greaterThan($deadline) => self::LATE,
            default => self::NOT_TRANSMITTED,
        };
    }
}
