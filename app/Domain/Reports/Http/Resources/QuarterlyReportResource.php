<?php

namespace App\Domain\Reports\Http\Resources;

use App\Domain\Reports\Enums\CgaPersonKind;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\CgaPerson;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Support\ReportCompleteness;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Rapport trimestriel du centre (F-RPT-02 à 07). En liste : période, statut, échéances, version,
 * complétude. En détail, en plus : contenu saisi, impôts des dirigeants, analyse qualitative, fiche
 * technique, transmission, accusé, propositions de la campagne de clôture (RG-OBL-12), actions permises.
 *
 * @property QuarterlyReport $resource
 */
class QuarterlyReportResource extends JsonResource
{
    private bool $detailed = false;

    /** @var array<string, mixed>|null */
    private ?array $proposals = null;

    /**
     * @param  array<string, mixed>|null  $proposals  nombres proposés par la campagne de clôture
     */
    public function detailed(?array $proposals = null): self
    {
        $this->detailed = true;
        $this->proposals = $proposals;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource;
        $completeness = $report->completeness;
        $editable = $report->isEditable();
        $data = [
            'id' => $report->id,
            'year' => $report->year,
            'quarter' => $report->quarter,
            'label' => __('report.quarter_label', ['quarter' => $report->quarter, 'year' => $report->year]),
            'period' => ['start' => $report->periodStart()->toDateString(), 'end' => $report->periodEnd()->toDateString()],
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'deadline' => $report->deadline->toDateString(),
            'legal_deadline' => $report->legal_deadline->toDateString(),
            'is_late' => $report->is_late,
            'is_legally_late' => $report->is_legally_late,
            'current_version' => $report->current_version,
            'completeness' => $completeness,
            'completeness_overall' => ReportCompleteness::overall($completeness),
            'transmitted_at' => $report->transmitted_at?->toIso8601ZuluString(),
            'acknowledged_at' => $report->acknowledged_at?->toDateString(),
        ];
        if (! $this->detailed) {
            return $data;
        }

        return [
            ...$data,
            'report_date' => $report->report_date?->toDateString(),
            'regional_directorate_label' => $report->regional_directorate_label,
            'content' => (object) $report->content,
            'people_taxes' => $report->people_taxes,
            'qualitative_analysis' => (object) $report->qualitative_analysis,
            'directors' => CgaPerson::query()
                ->where('organization_id', $report->organization_id)
                ->where('kind', CgaPersonKind::Director)
                ->where('active', true)
                ->orderBy('full_name')
                ->get()
                ->map(static fn (CgaPerson $person): array => ['id' => $person->id, 'full_name' => $person->full_name, 'function_in_cga' => $person->function_in_cga])
                ->all(),
            'closing_proposals' => $this->proposals,
            'transmission' => $report->transmitted_at === null ? null : [
                'transmitted_at' => $report->transmitted_at->toIso8601ZuluString(),
                'mode' => $report->transmission_mode?->value,
                'mode_label' => $report->transmission_mode?->label(),
                'reference' => $report->transmission_reference,
                'by' => $report->transmitter?->fullName(),
            ],
            'acknowledgement' => $report->acknowledged_at === null ? null : [
                'date' => $report->acknowledged_at->toDateString(),
                'reference' => $report->acknowledgement_reference,
                'file' => $report->acknowledgementFile === null ? null : [
                    'id' => $report->acknowledgementFile->id,
                    'name' => $report->acknowledgementFile->original_name,
                    'url' => app(StorageService::class)->temporaryUrl($report->acknowledgementFile),
                ],
            ],
            'can' => [
                'update' => $editable && Gate::allows('prepare', QuarterlyReport::class),
                'generate' => $editable && ReportCompleteness::isComplete($completeness) && Gate::allows('prepare', QuarterlyReport::class),
                'transmit' => $report->status === ReportStatus::Generated && Gate::allows('transmit', QuarterlyReport::class),
                'acknowledge' => $report->status === ReportStatus::Transmitted && Gate::allows('transmit', QuarterlyReport::class),
                'correct' => in_array($report->status, [ReportStatus::Transmitted, ReportStatus::Acknowledged], true) && Gate::allows('transmit', QuarterlyReport::class),
            ],
        ];
    }
}
