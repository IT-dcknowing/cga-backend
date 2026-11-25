<?php

namespace App\Domain\Statistics\Support;

use App\Domain\Referentials\Models\NationalReference;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;

/**
 * Indicateurs macro des statistiques nationales (F-STA-01) d'une année, sur les CGA abonnés (RG-STA-02) et leur
 * portefeuille CGA (RG-STA-05) :
 * - recettes fiscales du réseau : impôts et cotisations payés confirmés de l'année ;
 * - chiffre d'affaires des adhérents : chiffres d'affaires de référence de l'année (dernier jour calculé) ;
 * - contribution au budget de l'État : recettes fiscales du réseau ÷ recettes fiscales intérieures de l'année ;
 * - contribution au PIB : chiffre d'affaires des adhérents ÷ PIB nominal de l'année.
 * Une contribution est « non disponible » tant que la référence nationale de l'année n'est pas saisie
 * (RG-STA-03, F-REF-04).
 */
final class MacroIndicators
{
    private const RATE_ROUNDING = 6;

    public function __construct(private readonly OrgStatsSummary $summary) {}

    /**
     * @return array{year: int, tax_revenue: int, member_revenue: int, budget_contribution: array{available: bool, rate: float|null, label: string|null}, gdp_contribution: array{available: bool, rate: float|null, label: string|null}}
     */
    public function forYear(int $year): array
    {
        $byOrganization = Tenancy::bypass(
            fn (): array => $this->summary->byOrganization(CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31)),
            'Statistiques : indicateurs macro du réseau (F-STA-01).',
        );
        $taxRevenue = (int) array_sum(array_column($byOrganization, 'tax_paid_total'));
        $memberRevenue = (int) array_sum(array_column($byOrganization, 'declared_revenue'));
        $reference = NationalReference::query()->where('year', $year)->first();

        return [
            'year' => $year,
            'tax_revenue' => $taxRevenue,
            'member_revenue' => $memberRevenue,
            'budget_contribution' => $this->rate($taxRevenue, $reference?->domestic_tax_revenue, $year),
            'gdp_contribution' => $this->rate($memberRevenue, $reference?->nominal_gdp, $year),
        ];
    }

    /**
     * @return array{available: bool, rate: float|null, label: string|null}
     */
    private function rate(int $value, ?int $reference, int $year): array
    {
        if ($reference === null || $reference <= 0) {
            return ['available' => false, 'rate' => null, 'label' => (string) __('statistics.macro_unavailable', ['year' => $year])];
        }

        return ['available' => true, 'rate' => round($value / $reference, self::RATE_ROUNDING), 'label' => null];
    }
}
