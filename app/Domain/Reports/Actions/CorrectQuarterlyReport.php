<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * « Corriger » un rapport transmis (F-RPT-06, RG-RPT-05) : une nouvelle version (V2, V3…) s'ouvre en
 * brouillon avec son motif ; les versions transmises restent consultables, horodatées, avec leur
 * instantané. Le CNCGA garde l'état et la synthèse de la dernière transmission jusqu'à la suivante.
 */
class CorrectQuarterlyReport
{
    /**
     * @throws BusinessRuleException report_not_transmitted
     */
    public function execute(QuarterlyReport $report, string $reason): QuarterlyReport
    {
        return DB::transaction(function () use ($report, $reason): QuarterlyReport {
            $report = QuarterlyReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! in_array($report->status, [ReportStatus::Transmitted, ReportStatus::Acknowledged], true)) {
                throw new BusinessRuleException('report_not_transmitted');
            }

            $version = new QuarterlyReportVersion;
            $version->organization_id = $report->organization_id;
            $version->report_id = $report->id;
            $version->version = $report->current_version + 1;
            $version->reason = $reason;
            $version->save();

            $report->current_version = $version->version;
            $report->status = ReportStatus::Draft;
            $report->acknowledged_at = null;
            $report->acknowledgement_reference = null;
            $report->acknowledgement_file_id = null;
            $report->save();

            return $report;
        });
    }
}
