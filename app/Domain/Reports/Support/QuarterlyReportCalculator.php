<?php

namespace App\Domain\Reports\Support;

use App\Domain\Reports\Enums\CgaPersonKind;
use App\Domain\Reports\Models\CgaPerson;
use App\Domain\Reports\Models\CgaResource;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;

/**
 * Objet de données unique du rapport trimestriel (02-TRD §8.4) : fiche technique (section 1), capital
 * humain avec les impôts du trimestre (2), moyens matériels (3), contenu saisi (4 et 6), analyse
 * qualitative, états du portefeuille CGA par CDI (5), règle « augmentée » en vigueur (RG-RPT-08) et
 * sources des données (RG-RPT-12). Le classeur de compatibilité, le rapport Word et l'aperçu lisent le
 * même objet : leurs agrégats sont identiques par construction. Il est enregistré tel quel dans
 * l'instantané d'une version (RG-RPT-05).
 */
final class QuarterlyReportCalculator
{
    public function __construct(
        private readonly PortfolioStates $states,
        private readonly SettingsService $settings,
        private readonly ReportCharts $charts,
        private readonly ReportSynthesis $synthesis,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(QuarterlyReport $report, CarbonImmutable $now): array
    {
        $cga = $report->organization()->with('regionalDirectorate')->firstOrFail();
        /** @var array{basis: string, min_increase_pct: int|float, amount: string} $rule */
        $rule = (array) $this->settings->get('report.increase_rule');
        $taxes = collect($report->people_taxes)->keyBy('person_id');
        $states = $this->states->compute($report->organization_id, $report->year, $report->quarter, $rule);
        $trend = $this->trend($report);

        $people = CgaPerson::query()
            ->where('organization_id', $report->organization_id)
            ->where('active', true)
            ->orderBy('full_name')
            ->get()
            ->groupBy(static fn (CgaPerson $person): string => $person->kind->value)
            ->map(static fn ($group) => $group->map(static fn (CgaPerson $person): array => [
                'id' => $person->id,
                'full_name' => $person->full_name,
                'nationality' => $person->nationality,
                'profession' => $person->profession,
                'diplomas' => $person->diplomas,
                'function' => $person->function_in_cga,
                'start_date' => $person->start_date?->toDateString(),
                'practice_structure' => $person->practice_structure,
                'practice_function' => $person->practice_function,
                'practice_years' => $person->practice_years,
                'cnps_declared' => $person->cnps_declared,
                'taxes' => $person->kind === CgaPersonKind::Director ? array_diff_key((array) $taxes->get($person->id, []), ['person_id' => true]) : null,
            ])->values()->all())
            ->all();

        return [
            'report' => [
                'id' => $report->id,
                'year' => $report->year,
                'quarter' => $report->quarter,
                'label' => __('report.quarter_label', ['quarter' => $report->quarter, 'year' => $report->year]),
                'period_start' => $report->periodStart()->toDateString(),
                'period_end' => $report->periodEnd()->toDateString(),
                'report_date' => $report->report_date?->toDateString(),
                'deadline' => $report->deadline->toDateString(),
                'legal_deadline' => $report->legal_deadline->toDateString(),
                'computed_at' => $now->toIso8601ZuluString(),
            ],
            'cga' => [
                'name' => $cga->name,
                'sigle' => $cga->sigle,
                'ncc' => $cga->ncc,
                'rccm' => $cga->rccm,
                'legal_form' => $cga->legal_form,
                'share_capital' => $cga->share_capital,
                'agreement_number' => $cga->agreement_number,
                'agreement_date' => $cga->agreement_date?->toDateString(),
                'address' => $cga->address,
                'city' => $cga->city,
                'phones' => array_values(array_filter([$cga->phone_1, $cga->phone_2])),
                'email' => $cga->email,
                'regional_directorate' => $report->regional_directorate_label ?? $cga->regionalDirectorate?->name,
                'said' => $cga->said,
                'history' => $cga->history_text,
                'previous_year_revenue' => $cga->previous_year_revenue,
                'manager_name' => $cga->manager_name,
                'signatory_name' => $cga->signatory_name ?? $cga->manager_name,
                'signatory_title' => $cga->signatory_title,
            ],
            'people' => [
                'directors' => $people[CgaPersonKind::Director->value] ?? [],
                'employees' => $people[CgaPersonKind::Employee->value] ?? [],
                'occasionals' => $people[CgaPersonKind::Occasional->value] ?? [],
            ],
            'resources' => CgaResource::query()
                ->where('organization_id', $report->organization_id)
                ->get()
                ->mapWithKeys(static fn (CgaResource $resource): array => [$resource->category->value => [
                    'quantity' => $resource->quantity,
                    'description' => $resource->description,
                    'need' => $resource->need,
                ]])
                ->all(),
            'content' => $report->content,
            'qualitative_analysis' => $report->qualitative_analysis,
            'states' => $states,
            // Rapport modernisé (étape 5.11) : séries des graphiques et synthèse chiffrée pré-rédigée, tirées
            // des mêmes états que le classeur.
            'trend' => $trend,
            'charts' => $this->charts->build($states, $report->content, $trend),
            'synthesis' => $this->synthesis->propose($states, $report->content, $trend, $report->periodEnd()->toDateString()),
            'increase_rule' => $rule,
        ];
    }

    /**
     * Adhérents actifs du portefeuille CGA à la fin des derniers trimestres (reports.trend_quarters), le
     * dernier étant celui du rapport.
     *
     * @return list<array{label: string, tee: int, rme: int, total: int}>
     */
    private function trend(QuarterlyReport $report): array
    {
        $trend = [];
        for ($back = (int) config('reports.trend_quarters') - 1; $back >= 0; $back--) {
            $start = CarbonImmutable::create($report->year, ($report->quarter - 1) * 3 + 1, 1)->subMonthsNoOverflow($back * 3);
            ['tee' => $tee, 'rme' => $rme] = $this->states->activeByRegime($report->organization_id, $start->addMonthsNoOverflow(2)->endOfMonth()->startOfDay());
            $trend[] = ['label' => (string) __('report.quarter_label', ['quarter' => $start->quarter, 'year' => $start->year]), 'tee' => $tee, 'rme' => $rme, 'total' => $tee + $rme];
        }

        return $trend;
    }
}
