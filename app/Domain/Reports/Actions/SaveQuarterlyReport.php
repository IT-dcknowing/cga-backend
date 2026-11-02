<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Support\ReportCompleteness;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement automatique du rapport trimestriel (F-RPT-02) : champs des sections 4 et 6 fusionnés
 * dans le contenu, impôts du trimestre des dirigeants, analyse qualitative, date et Direction régionale
 * de la fiche technique ; complétude recalculée (RG-RPT-02). Un rapport généré modifié repasse en
 * brouillon (il se régénère avant transmission) ; un rapport transmis ne change plus : il se corrige par
 * une nouvelle version (RG-RPT-05).
 */
class SaveQuarterlyReport
{
    public function __construct(private readonly ReportCompleteness $completeness) {}

    /**
     * @param  array{content?: array<string, mixed>, qualitative_analysis?: array<string, string|null>, people_taxes?: list<array{person_id: string, its: int|null, igr: int|null, cnps: int|null}>, report_date?: string|null, regional_directorate_label?: string|null}  $changes
     *
     * @throws BusinessRuleException report_not_editable
     */
    public function execute(QuarterlyReport $report, array $changes): QuarterlyReport
    {
        return DB::transaction(function () use ($report, $changes): QuarterlyReport {
            $report = QuarterlyReport::query()->with('organization')->lockForUpdate()->findOrFail($report->id);
            if (! $report->isEditable()) {
                throw new BusinessRuleException('report_not_editable');
            }

            if (array_key_exists('content', $changes)) {
                $report->content = [...$report->content, ...$changes['content']];
            }
            if (array_key_exists('qualitative_analysis', $changes)) {
                $report->qualitative_analysis = [...$report->qualitative_analysis, ...$changes['qualitative_analysis']];
            }
            if (array_key_exists('people_taxes', $changes)) {
                $report->people_taxes = $changes['people_taxes'];
            }
            if (array_key_exists('report_date', $changes)) {
                $report->report_date = $changes['report_date'] === null ? null : CarbonImmutable::parse($changes['report_date']);
            }
            if (array_key_exists('regional_directorate_label', $changes)) {
                $report->regional_directorate_label = $changes['regional_directorate_label'];
            }
            if ($report->status === ReportStatus::Generated && $report->isDirty()) {
                $report->status = ReportStatus::Draft;
            }
            $report->completeness = $this->completeness->compute($report, $report->organization);
            $report->save();

            return $report;
        });
    }
}
