<?php

namespace App\Domain\Exports\Exporters;

use App\Domain\Exports\Contracts\Exporter;
use App\Domain\Exports\Data\ExportCriteria;
use App\Domain\Exports\Data\ExportSheet;
use App\Domain\Statistics\Support\NationalStatistics;
use App\Domain\Statistics\Support\StatPeriod;
use Carbon\CarbonImmutable;

/**
 * Statistiques nationales (F-EXP-01, bouton « Exporter Excel » de /cncga/statistiques) d'une période (l'année en
 * cours sans période) : synthèse (couverture RG-STA-01, indicateurs macro RG-STA-03), recettes par nature, par CGA,
 * adhérents par régime, par secteur et par Direction régionale. Lues dans les agrégats seuls (portefeuille CGA des
 * CGA abonnés, RG-STA-02, RG-STA-05), sans donnée nominative.
 */
final class StatisticsExporter implements Exporter
{
    public function __construct(private readonly NationalStatistics $statistics) {}

    public function filterRules(): array
    {
        return [];
    }

    public function sheets(ExportCriteria $criteria): iterable
    {
        $today = CarbonImmutable::today();
        $period = $criteria->from === null
            ? StatPeriod::of(StatPeriod::YEAR, $today)
            : StatPeriod::of(StatPeriod::CUSTOM, $today, $criteria->from, $criteria->to);
        $data = $this->statistics->build($period, $today);
        $label = static fn (string $key): string => (string) __("exports.columns.statistics.{$key}");
        $rate = static fn (array $contribution): string|float => $contribution['available'] ? (float) $contribution['rate'] : (string) $contribution['label'];

        yield new ExportSheet((string) __('exports.sheets.statistics_summary'), [$label('indicator'), $label('value')], [
            [$label('coverage'), $data['coverage']['label']],
            [$label('tax_revenue'), $data['tax_revenue']['total']],
            [$label('member_revenue'), $data['macro']['member_revenue']],
            [$label('budget_contribution'), $rate($data['macro']['budget_contribution'])],
            [$label('gdp_contribution'), $rate($data['macro']['gdp_contribution'])],
        ]);
        yield new ExportSheet((string) __('exports.sheets.statistics_natures'), [$label('nature'), $label('amount')], array_map(
            static fn (string $nature, int $amount): array => [$nature, $amount],
            array_keys($data['tax_revenue']['by_nature']),
            array_values($data['tax_revenue']['by_nature']),
        ));
        yield new ExportSheet((string) __('exports.sheets.statistics_cgas'), [$label('organization'), $label('members'), $label('tax_revenue'), $label('member_revenue')], array_map(
            static fn (array $cga): array => [$cga['name'], $cga['active_members'], $cga['tax_paid_total'], $cga['declared_revenue']],
            $data['by_cga'],
        ));
        yield new ExportSheet((string) __('exports.sheets.statistics_regimes'), [$label('regime'), $label('members')], array_map(
            static fn (string $regime, int $members): array => [$regime, $members],
            array_keys($data['by_regime']),
            array_values($data['by_regime']),
        ));
        yield new ExportSheet((string) __('exports.sheets.statistics_sectors'), [$label('sector'), $label('members')], array_map(
            static fn (array $sector): array => [$sector['name'] ?? (string) __('exports.values.unspecified'), $sector['members']],
            $data['by_sector'],
        ));
        yield new ExportSheet((string) __('exports.sheets.statistics_directorates'), [$label('directorate'), $label('cgas'), $label('members'), $label('member_revenue')], array_map(
            static fn (array $directorate): array => [$directorate['name'] ?? (string) __('exports.values.unspecified'), $directorate['cgas'], $directorate['members'], $directorate['revenue']],
            $data['by_directorate'],
        ));
    }
}
