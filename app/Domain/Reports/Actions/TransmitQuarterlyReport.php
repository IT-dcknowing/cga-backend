<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Documents\Actions\IssueDocument;
use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Reports\Composers\ReportSnapshot;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Enums\TransmissionMode;
use App\Domain\Reports\Events\ReportTransmitted;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Enregistrer la transmission » (F-RPT-04) : le CGA a envoyé lui-même le rapport généré au SP-CGA
 * (CR-05) ; il en note la date, le mode et la référence. La version en cours est horodatée, le
 * bordereau est produit, et le tableau de synthèse agrégé (feuille Résumé, sans aucun nom d'adhérent)
 * devient la seule donnée visible du CNCGA (RG-RPT-04).
 */
class TransmitQuarterlyReport
{
    public function __construct(private readonly IssueDocument $documents) {}

    /**
     * @throws BusinessRuleException report_not_generated
     */
    public function execute(QuarterlyReport $report, CarbonImmutable $transmittedOn, TransmissionMode $mode, ?string $reference, Membership $requester, CarbonImmutable $now): QuarterlyReport
    {
        return DB::transaction(function () use ($report, $transmittedOn, $mode, $reference, $requester, $now): QuarterlyReport {
            $report = QuarterlyReport::query()->with('organization')->lockForUpdate()->findOrFail($report->id);
            if ($report->status !== ReportStatus::Generated) {
                throw new BusinessRuleException('report_not_generated');
            }
            $version = QuarterlyReportVersion::query()->where('report_id', $report->id)->where('version', $report->current_version)->lockForUpdate()->firstOrFail();
            $summary = $version->snapshot['states']['sheets']['summary'] ?? ['rows' => [], 'totals' => []];

            $report->status = ReportStatus::Transmitted;
            $report->transmitted_at = $transmittedOn->startOfDay();
            $report->transmission_mode = $mode;
            $report->transmission_reference = $reference;
            $report->transmitted_by = $requester->user_id;
            $report->summary = [
                'version' => $version->version,
                'period' => ['start' => $report->periodStart()->toDateString(), 'end' => $report->periodEnd()->toDateString()],
                'rows' => array_map(static fn (array $row): array => [
                    'tax_office' => $row['tax_office'] === null ? null : ['code' => $row['tax_office']['code'], 'name' => $row['tax_office']['name']],
                    ...array_diff_key($row, ['tax_office' => true]),
                ], $summary['rows']),
                'totals' => $summary['totals'],
            ];
            $report->save();

            $slip = $this->documents->execute(new DocumentRequest(
                type: DocumentType::ReportTransmissionSlip,
                title: (string) __('report.slip_title', ['label' => __('report.quarter_label', ['quarter' => $report->quarter, 'year' => $report->year])]),
                number: strtr((string) config('reports.document_number'), ['{ABR}' => (string) $report->organization->numbering_abbreviation, '{YYYY}' => (string) $report->year, '{Q}' => (string) $report->quarter]),
                fiscalYear: $report->year,
                visibleToMember: false,
                sourceType: ReportSnapshot::SOURCE_TYPE,
                sourceId: $version->id,
            ), $requester);
            $version->transmitted_at = $now;
            $version->slip_document_id = $slip->id;
            $version->save();
            ReportTransmitted::dispatch($report->organization_id, $report->year, $report->quarter);

            return $report;
        });
    }
}
