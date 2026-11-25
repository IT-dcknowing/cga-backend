<?php

namespace App\Domain\Reports\Composers;

use App\Domain\Documents\Composers\CgaIdentity;
use App\Domain\Documents\Composers\DocumentComposer;
use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Rapport trimestriel Word (modèle quarterly_report_docx) d'une version, rempli depuis son instantané
 * (RG-RPT-05) : fiche technique, dirigeants, salariés et occasionnels, moyens, gestion des adhérents,
 * effectifs du portefeuille CGA (Résumé), recettes fiscales du trimestre, mouvements (nouveaux et
 * résiliés), difficultés et prévisions ; signature, cachet et QR de vérification.
 */
class QuarterlyReportDocxComposer implements DocumentComposer
{
    public function __construct(private readonly CgaIdentity $identity) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $data = ReportSnapshot::of($document);
        $organization = Organization::query()->with(['logo', 'signature', 'stamp'])->findOrFail($document->organization_id);
        $cga = $data['cga'];
        $content = $data['content'];
        $states = $data['states'];
        $totals = $states['sheets']['summary']['totals'];
        $receipts = $states['receipts'];
        $text = static fn (?string $value): string => DocumentFormat::text($value);
        $resource = static fn (string $category): string => isset($data['resources'][$category]) ? (string) $data['resources'][$category]['quantity'] : DocumentFormat::text(null);
        $date = static fn (?string $value): string => DocumentFormat::date($value === null ? null : CarbonImmutable::parse($value));

        $values = [
            ...$this->identity->values($organization),
            'cga_seat' => $text($cga['address']),
            'cga_location' => $text($cga['city']),
            'cga_contacts' => $text(implode(' · ', array_filter([...$cga['phones'], $cga['email']]))),
            'cga_tax_service' => $text($cga['said']),
            'cga_legal_form_capital' => $text(trim(($cga['legal_form'] ?? '').' '.($cga['share_capital'] === null ? '' : DocumentFormat::amount($cga['share_capital'])))),
            'cga_history' => $text($cga['history']),
            'cga_previous_year_revenue' => DocumentFormat::amount($cga['previous_year_revenue']),
            'report_quarter' => __('report.quarters.'.$data['report']['quarter']).' '.$data['report']['year'],
            'report_date' => $date($data['report']['report_date']),
            'regional_directorate' => $text($cga['regional_directorate']),
            'res_rooms' => $resource('premises'),
            'res_training_rooms' => $resource('training_rooms'),
            'res_furniture' => $resource('furniture'),
            'res_computers' => $resource('computers'),
            'res_software' => $resource('software'),
            'res_projectors' => $resource('projectors'),
            'res_vehicles' => $resource('vehicles'),
            'trainings' => $text($content['trainings'] ?? null),
            'smt_count' => __('report.established_transmitted', ['established' => (int) ($content['smt_established'] ?? 0), 'transmitted' => (int) ($content['smt_transmitted'] ?? 0)]),
            'balance_sheet_count' => __('report.established_transmitted', ['established' => (int) ($content['balance_sheets_established'] ?? 0), 'transmitted' => (int) ($content['balance_sheets_transmitted'] ?? 0)]),
            'meetings' => $text(implode(' · ', array_filter([$content['meetings_dgi'] ?? null, $content['meetings_partners'] ?? null]))),
            'difficulties' => $text(implode(' · ', array_filter([$content['difficulties_internal'] ?? null, $content['difficulties_partners'] ?? null]))),
            'suggestions' => $text($content['suggestions'] ?? null),
            'forecasts' => $text($content['forecasts'] ?? null),
            'tax_tee' => DocumentFormat::amount($receipts['tee'] ?? 0),
            'tax_rme' => DocumentFormat::amount($receipts['rme'] ?? 0),
            'tax_vat' => DocumentFormat::amount($receipts['tva'] ?? 0),
            'tax_its' => DocumentFormat::amount($receipts['its'] ?? 0),
            'tax_cnps' => DocumentFormat::amount($receipts['cnps'] ?? 0),
            'tax_total' => DocumentFormat::amount($receipts['total']),
            'signatory_name' => $text($cga['signatory_name']),
            'signatory_title' => $text($cga['signatory_title'] ?? __('documents.default_signatory_title')),
            'verification_reference' => (string) $document->verification_token,
        ];
        foreach (['active_tee', 'active_rme', 'active_total', 'new_tee', 'new_rme', 'new_total', 'terminated_tee', 'terminated_rme', 'terminated_total'] as $key) {
            $values[$key] = (string) $totals[$key];
        }

        $people = static fn (array $list, callable $row, array $empty): array => $list === [] ? [$empty] : array_map($row, $list, array_keys($list));

        return new DocumentContent($values, $this->identity->images($organization), [
            'director_name' => $people($data['people']['directors'], static fn (array $p, int $i): array => [
                'director_index' => (string) ($i + 1), 'director_name' => $p['full_name'], 'director_profession' => $text($p['profession']),
                'director_function' => $text($p['function']), 'director_start_date' => $date($p['start_date']),
                'director_experience' => $text(trim(($p['practice_structure'] ?? '').' '.($p['practice_years'] === null ? '' : __('report.years', ['count' => $p['practice_years']])))),
            ], array_fill_keys(['director_index', 'director_name', 'director_profession', 'director_function', 'director_start_date', 'director_experience'], $text(null))),
            'employee_name' => $people($data['people']['employees'], static fn (array $p, int $i): array => [
                'employee_index' => (string) ($i + 1), 'employee_name' => $p['full_name'], 'employee_profession' => $text($p['profession']),
                'employee_function' => $text($p['function']), 'employee_start_date' => $date($p['start_date']),
                'employee_cnps' => (string) __('report.cnps.'.($p['cnps_declared'] === true ? 'declared' : 'not_declared')),
            ], array_fill_keys(['employee_index', 'employee_name', 'employee_profession', 'employee_function', 'employee_start_date', 'employee_cnps'], $text(null))),
            'occasional_name' => $people($data['people']['occasionals'], static fn (array $p, int $i): array => [
                'occasional_index' => (string) ($i + 1), 'occasional_name' => $p['full_name'], 'occasional_diploma' => $text($p['diplomas']),
                'occasional_function' => $text($p['function']), 'occasional_since' => $date($p['start_date']),
            ], array_fill_keys(['occasional_index', 'occasional_name', 'occasional_diploma', 'occasional_function', 'occasional_since'], $text(null))),
            'movement_name' => $this->movements($states['sheets']),
        ]);
    }

    /**
     * Mouvements du trimestre : nouveaux adhérents puis résiliés, TEE et RME.
     *
     * @param  array<string, mixed>  $sheets
     * @return list<array<string, string>>
     */
    private function movements(array $sheets): array
    {
        $movements = [];
        foreach (['new_tee' => ['TEE', 'new'], 'new_rme' => ['RME', 'new'], 'terminations_tee' => ['TEE', 'terminated'], 'terminations_rme' => ['RME', 'terminated']] as $sheet => [$regime, $status]) {
            foreach ($sheets[$sheet] as $group) {
                foreach ($group['rows'] as $row) {
                    $movements[] = [
                        'movement_name' => (string) $row['name'], 'movement_ncc' => (string) $row['ncc'], 'movement_regime' => $regime,
                        'movement_number' => DocumentFormat::text($row['membership_number'] ?? null), 'movement_status' => (string) __('report.movements.'.$status),
                    ];
                }
            }
        }

        return $movements !== [] ? $movements : [array_fill_keys(['movement_name', 'movement_ncc', 'movement_regime', 'movement_number', 'movement_status'], DocumentFormat::text(null))];
    }
}
