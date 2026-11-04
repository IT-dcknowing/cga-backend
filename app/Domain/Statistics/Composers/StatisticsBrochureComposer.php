<?php

namespace App\Domain\Statistics\Composers;

use App\Domain\Documents\Composers\DocumentComposer;
use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Statistics\Support\NationalStatistics;
use App\Domain\Statistics\Support\StatPeriod;
use Carbon\CarbonImmutable;

/**
 * Plaquette statistique annuelle du CNCGA (F-STA-03, modèle statistics_brochure) : les statistiques nationales de
 * l'année du document (F-STA-01), lues dans les agrégats seuls — couverture (RG-STA-01), indicateurs macro ou
 * « non disponible » (RG-STA-03), adhérents par régime, recettes par nature, par CGA, adhérents par secteur et par
 * Direction régionale. Aucune donnée nominative d'adhérent.
 */
class StatisticsBrochureComposer implements DocumentComposer
{
    private const PERCENT = 100;

    private const NONE = '—';

    public function __construct(private readonly NationalStatistics $statistics) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $issuer = Organization::query()->findOrFail($document->organization_id);
        $year = (int) $document->fiscal_year;
        $today = CarbonImmutable::today();
        $data = $this->statistics->build(StatPeriod::of(StatPeriod::YEAR, CarbonImmutable::create($year, 1, 1)), $today);
        $contribution = static fn (array $rate): string => $rate['available'] ? DocumentFormat::rate(self::PERCENT * (float) $rate['rate']) : (string) $rate['label'];
        $regimes = $data['by_regime'];

        return new DocumentContent([
            'cga_name' => $issuer->name,
            'brochure_year' => (string) $year,
            'coverage_label' => $data['coverage']['label'],
            'computed_date' => $data['computed_at'] === null ? self::NONE : DocumentFormat::date(CarbonImmutable::parse($data['computed_at'])),
            'tax_revenue_total' => DocumentFormat::amount($data['tax_revenue']['total']),
            'member_revenue_total' => DocumentFormat::amount($data['macro']['member_revenue']),
            'budget_contribution' => $contribution($data['macro']['budget_contribution']),
            'gdp_contribution' => $contribution($data['macro']['gdp_contribution']),
            'members_total' => (string) array_sum($regimes),
            'members_tee' => (string) $regimes['TEE'],
            'members_rme' => (string) $regimes['RME'],
            'members_rsi' => (string) $regimes['RSI'],
            'document_date' => DocumentFormat::date($document->issued_at),
        ], rows: [
            'nature_label' => array_map(static fn (string $nature, int $amount): array => [
                'nature_label' => mb_strtoupper($nature),
                'nature_amount' => DocumentFormat::amount($amount),
            ], array_keys($data['tax_revenue']['by_nature']), array_values($data['tax_revenue']['by_nature'])),
            'by_cga_name' => array_map(static fn (array $cga): array => [
                'by_cga_name' => (string) $cga['name'],
                'by_cga_members' => (string) $cga['active_members'],
                'by_cga_tax' => DocumentFormat::amount($cga['tax_paid_total']),
                'by_cga_revenue' => DocumentFormat::amount($cga['declared_revenue']),
            ], $data['by_cga']),
            'sector_name' => array_map(static fn (array $sector): array => [
                'sector_name' => $sector['name'] ?? (string) __('exports.values.unspecified'),
                'sector_members' => (string) $sector['members'],
            ], $data['by_sector']),
            'directorate_name' => array_map(static fn (array $directorate): array => [
                'directorate_name' => $directorate['name'] ?? (string) __('exports.values.unspecified'),
                'directorate_cgas' => (string) $directorate['cgas'],
                'directorate_members' => (string) $directorate['members'],
                'directorate_revenue' => DocumentFormat::amount($directorate['revenue']),
            ], $data['by_directorate']),
        ]);
    }
}
