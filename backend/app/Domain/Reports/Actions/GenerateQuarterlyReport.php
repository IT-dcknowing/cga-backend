<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Documents\Actions\IssueDocument;
use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Reports\Composers\ReportSnapshot;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use App\Domain\Reports\Support\QuarterlyReportCalculator;
use App\Domain\Reports\Support\ReportCompleteness;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Générer le rapport » (F-RPT-03, 02-TRD §8.4) : quand toutes les sections sont à 100 % (RG-RPT-02),
 * l'objet de données est calculé une fois et enregistré dans la version en cours (RG-RPT-05) avec le
 * paramétrage « augmentée » en vigueur (RG-RPT-08) ; ses documents sont émis depuis cet instantané —
 * rapport Word et PDF, classeur officiel de compatibilité si le réseau l'exige (RG-RPT-10). Avant
 * transmission, une nouvelle génération remplace l'instantané de la version en cours ; après une
 * correction, elle remplit la nouvelle version ouverte avec son motif.
 */
class GenerateQuarterlyReport
{
    public function __construct(
        private readonly QuarterlyReportCalculator $calculator,
        private readonly ReportCompleteness $completeness,
        private readonly IssueDocument $documents,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @throws BusinessRuleException report_not_editable, report_incomplete, cga_profile_incomplete, template_missing
     */
    public function execute(QuarterlyReport $report, Membership $requester, CarbonImmutable $now): QuarterlyReport
    {
        return DB::transaction(function () use ($report, $requester, $now): QuarterlyReport {
            $report = QuarterlyReport::query()->with('organization')->lockForUpdate()->findOrFail($report->id);
            if (! $report->isEditable()) {
                throw new BusinessRuleException('report_not_editable');
            }
            $report->completeness = $this->completeness->compute($report, $report->organization);
            if (! ReportCompleteness::isComplete($report->completeness)) {
                throw new BusinessRuleException('report_incomplete', details: ['completeness' => $report->completeness]);
            }
            $this->documents->ensureProfileComplete($report->organization_id);

            $version = QuarterlyReportVersion::query()
                ->where('report_id', $report->id)
                ->where('version', $report->current_version)
                ->whereNull('transmitted_at')
                ->first();
            if ($version === null) {
                $version = new QuarterlyReportVersion;
                $version->organization_id = $report->organization_id;
                $version->report_id = $report->id;
                $version->version = $report->current_version + 1;
            }
            $version->snapshot = $this->calculator->build($report, $now);
            $version->generated_at = $now;
            $version->generated_by = $requester->user_id;
            $version->save();

            $number = strtr((string) config('reports.document_number'), [
                '{ABR}' => (string) $report->organization->numbering_abbreviation,
                '{YYYY}' => (string) $report->year,
                '{Q}' => (string) $report->quarter,
            ]);
            $title = (string) __('report.document_title', ['label' => __('report.quarter_label', ['quarter' => $report->quarter, 'year' => $report->year])]);
            $issue = fn (DocumentType $type) => $this->documents->execute(new DocumentRequest(
                type: $type,
                title: $title,
                number: $number,
                fiscalYear: $report->year,
                visibleToMember: false,
                sourceType: ReportSnapshot::SOURCE_TYPE,
                sourceId: $version->id,
            ), $requester);

            $word = $issue(DocumentType::QuarterlyReportDocx);
            $version->docx_document_id = $word->id;
            $version->pdf_document_id = $word->id;
            $version->xlsx_document_id = (bool) $this->settings->get('report.legacy_workbook_enabled')
                ? $issue(DocumentType::QuarterlyReportXlsx)->id
                : null;
            $version->save();

            $report->current_version = $version->version;
            $report->status = ReportStatus::Generated;
            $report->save();

            return $report;
        });
    }
}
