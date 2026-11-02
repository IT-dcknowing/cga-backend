<?php

namespace App\Domain\Reports\Composers;

use App\Domain\Documents\Composers\CgaIdentity;
use App\Domain\Documents\Composers\DocumentComposer;
use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use LogicException;

/**
 * Bordereau de transmission d'une version du rapport trimestriel au SP-CGA (F-RPT-04) : rapport,
 * période, version, date, mode et référence de la transmission faite par le CGA lui-même (CR-05).
 */
class ReportTransmissionSlipComposer implements DocumentComposer
{
    public function __construct(private readonly CgaIdentity $identity) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $version = QuarterlyReportVersion::query()->with('report')->find($document->source_id)
            ?? throw new LogicException("Bordereau {$document->id} sans version de rapport.");
        $report = $version->report;
        $organization = Organization::query()->with(['logo', 'signature', 'stamp'])->findOrFail($document->organization_id);

        return new DocumentContent([
            ...$this->identity->values($organization),
            'report_label' => (string) __('report.quarter_label', ['quarter' => $report->quarter, 'year' => $report->year]),
            'report_period' => (string) __('report.period', ['start' => DocumentFormat::date($report->periodStart()), 'end' => DocumentFormat::date($report->periodEnd())]),
            'report_version' => 'V'.$version->version,
            'transmission_date' => DocumentFormat::date($report->transmitted_at),
            'transmission_mode' => DocumentFormat::text($report->transmission_mode?->label()),
            'transmission_reference' => DocumentFormat::text($report->transmission_reference),
            'document_date' => DocumentFormat::date($document->issued_at),
        ], $this->identity->images($organization));
    }
}
