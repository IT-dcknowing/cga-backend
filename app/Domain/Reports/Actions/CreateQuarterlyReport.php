<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Support\ClosingProposals;
use App\Domain\Reports\Support\ReportCompleteness;
use App\Domain\Reports\Support\ReportDeadlines;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Préparer le rapport T{n} » (F-RPT-02) : un seul rapport par CGA, année et trimestre, pour le
 * trimestre en cours ou le précédent ; échéances J et J+15 fixées à la création (RG-RPT-06, 07) ;
 * Direction régionale reprise de la fiche du centre ; nombres de SMT et de bilans proposés depuis la
 * campagne de clôture (RG-OBL-12).
 */
class CreateQuarterlyReport
{
    public function __construct(
        private readonly ReportDeadlines $deadlines,
        private readonly ClosingProposals $proposals,
        private readonly ReportCompleteness $completeness,
    ) {}

    /**
     * @throws BusinessRuleException report_exists
     */
    public function execute(Organization $cga, int $year, int $quarter): QuarterlyReport
    {
        return DB::transaction(function () use ($cga, $year, $quarter): QuarterlyReport {
            // Verrou sur le centre : deux préparations simultanées du même trimestre ne créent qu'un rapport.
            Organization::query()->whereKey($cga->id)->lockForUpdate()->first();
            if (QuarterlyReport::query()->where('organization_id', $cga->id)->where('year', $year)->where('quarter', $quarter)->exists()) {
                throw new BusinessRuleException('report_exists');
            }

            $deadline = $this->deadlines->deadline($year, $quarter);
            $report = new QuarterlyReport;
            $report->organization_id = $cga->id;
            $report->year = $year;
            $report->quarter = $quarter;
            $report->status = ReportStatus::Draft;
            $report->deadline = $deadline;
            $report->legal_deadline = $this->deadlines->legalDeadline($deadline);
            $report->regional_directorate_label = $cga->regionalDirectorate?->name;
            $proposals = $this->proposals->forReport($cga->id, $year);
            $report->content = $proposals === null ? [] : array_diff_key($proposals, ['fiscal_year' => true]);
            $report->completeness = $this->completeness->compute($report, $cga);
            $report->save();

            return $report;
        });
    }

    /**
     * Trimestres que l'on peut préparer à une date : le trimestre en cours et le précédent.
     *
     * @return list<array{year: int, quarter: int}>
     */
    public static function creatable(CarbonImmutable $today): array
    {
        $previous = $today->startOfQuarter()->subDay();

        return [
            ['year' => $today->year, 'quarter' => $today->quarter],
            ['year' => $previous->year, 'quarter' => $previous->quarter],
        ];
    }
}
