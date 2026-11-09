<?php

namespace App\Domain\Reports\Support;

use App\Domain\Reports\Charts\ReportChart;

/**
 * Séries des graphiques du rapport modernisé (F-RPT-03, 02-TRD §8.4), calculées à partir de l'objet de
 * données du rapport — les mêmes agrégats que le classeur de compatibilité : portefeuille par régime et
 * par CDI, évolution des adhérents actifs, nouveaux et résiliés, recouvrement de la TEE, paiements RME par
 * nature, SMT et bilans de la campagne de clôture. Titres et séries : lang/fr/report.php.
 */
final class ReportCharts
{
    /**
     * @param  array<string, mixed>  $states  états du portefeuille (PortfolioStates)
     * @param  array<string, mixed>  $content  contenu saisi (compteurs de la campagne de clôture)
     * @param  list<array{label: string, tee: int, rme: int, total: int}>  $trend  adhérents actifs à la fin de chaque trimestre
     * @return array<string, array{type: string, title: string, categories: list<string>, series: array<string, list<int|float>>, value_axis: string|null}>
     */
    public function build(array $states, array $content, array $trend): array
    {
        $sheets = $states['sheets'];
        $summary = $sheets['summary'];
        $offices = array_map(self::office(...), $summary['rows']);
        $teeGroups = $sheets['tee_follow_up'];
        $rmeTotals = [];
        foreach ((array) config('reports.rme_columns') as $column => $natures) {
            $rmeTotals[(string) __('report.rme_columns.'.$column)] = (int) array_sum(array_map(static fn (array $group): int => (int) ($group['totals'][$column] ?? 0), $sheets['rme_follow_up']));
        }
        $count = (string) __('report.charts.members');
        $amount = (string) __('report.charts.amount');

        return [
            'chart_regimes' => self::chart('pie', 'regimes', ['TEE', 'RME'], [$count => [$summary['totals']['active_tee'], $summary['totals']['active_rme']]], null),
            'chart_tax_offices' => self::chart('column', 'tax_offices', $offices === [] ? [(string) __('report.no_tax_office')] : $offices, [
                'TEE' => $offices === [] ? [0] : array_map(static fn (array $row): int => (int) $row['active_tee'], $summary['rows']),
                'RME' => $offices === [] ? [0] : array_map(static fn (array $row): int => (int) $row['active_rme'], $summary['rows']),
            ], $count),
            'chart_trend' => self::chart('line', 'trend', array_column($trend, 'label'), [(string) __('report.charts.active') => array_column($trend, 'total')], $count),
            'chart_movements' => self::chart('column', 'movements', ['TEE', 'RME'], [
                (string) __('report.charts.new') => [$summary['totals']['new_tee'], $summary['totals']['new_rme']],
                (string) __('report.charts.terminated') => [$summary['totals']['terminated_tee'], $summary['totals']['terminated_rme']],
            ], $count),
            'chart_tee_recovery' => self::chart('column', 'tee_recovery', $teeGroups === [] ? [(string) __('report.no_tax_office')] : array_map(self::office(...), $teeGroups), [
                (string) __('report.charts.due') => $teeGroups === [] ? [0] : array_map(static fn (array $group): int => (int) ($group['totals']['quarter_net'] ?? 0), $teeGroups),
                (string) __('report.charts.paid') => $teeGroups === [] ? [0] : array_map(static fn (array $group): int => (int) ($group['totals']['quarter_paid'] ?? 0), $teeGroups),
            ], $amount),
            'chart_rme_natures' => self::chart('bar', 'rme_natures', array_keys($rmeTotals), [$amount => array_values($rmeTotals)], $amount),
            'chart_closing' => self::chart('column', 'closing', [(string) __('report.charts.smt'), (string) __('report.charts.balance_sheets')], [
                (string) __('report.charts.established') => [(int) ($content['smt_established'] ?? 0), (int) ($content['balance_sheets_established'] ?? 0)],
                (string) __('report.charts.transmitted') => [(int) ($content['smt_transmitted'] ?? 0), (int) ($content['balance_sheets_transmitted'] ?? 0)],
            ], $count),
        ];
    }

    /**
     * Graphique du document à partir de sa description enregistrée dans l'objet de données.
     *
     * @param  array{type: string, title: string, categories: list<string>, series: array<string, list<int|float>>, value_axis: string|null}  $chart
     */
    public static function toChart(array $chart): ReportChart
    {
        return new ReportChart($chart['type'], $chart['title'], $chart['categories'], $chart['series'], $chart['value_axis']);
    }

    /**
     * @param  list<string>  $categories
     * @param  array<string, list<int|float>>  $series
     * @return array{type: string, title: string, categories: list<string>, series: array<string, list<int|float>>, value_axis: string|null}
     */
    private static function chart(string $type, string $key, array $categories, array $series, ?string $valueAxis): array
    {
        return ['type' => $type, 'title' => (string) __('report.charts.titles.'.$key), 'categories' => $categories, 'series' => $series, 'value_axis' => $valueAxis];
    }

    /**
     * @param  array{tax_office: array{name: string}|null}  $group
     */
    private static function office(array $group): string
    {
        return $group['tax_office']['name'] ?? (string) __('report.no_tax_office');
    }
}
