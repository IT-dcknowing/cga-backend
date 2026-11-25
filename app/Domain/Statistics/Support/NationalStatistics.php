<?php

namespace App\Domain\Statistics\Support;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Statistics\Models\StatOrgDaily;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques nationales du CNCGA (F-STA-01, 03-APPFLOW §3.2) d'une période, lues dans les seuls agrégats
 * (02-TRD §9), sur les CGA abonnés et leur portefeuille CGA (RG-STA-02, RG-STA-05), avec la couverture
 * (RG-STA-01) : indicateurs macro de l'année (RG-STA-03), recettes fiscales par nature et par CGA, adhérents par
 * régime et par secteur, carte économique par Direction régionale (chiffre d'affaires, CGA et adhérents des CGA
 * de la DR) et détail par commune (adhérents domiciliés dans les communes de la DR). Aucune donnée nominative
 * d'adhérent : des nombres et des montants par CGA, par référentiel ou par nature.
 */
final class NationalStatistics
{
    private const UNSPECIFIED = 'none';

    public function __construct(
        private readonly OrgStatsSummary $summary,
        private readonly MacroIndicators $macro,
        private readonly StatsCoverage $coverage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(StatPeriod $period, CarbonImmutable $today): array
    {
        $until = $period->until($today);
        $byOrganization = $this->read(fn (): array => $this->summary->byOrganization($period->from, $until));
        $names = Organization::query()->whereIn('id', array_keys($byOrganization))->get(['id', 'name', 'sigle'])->keyBy('id');

        $byCga = [];
        foreach ($byOrganization as $organizationId => $values) {
            $cga = $names->get($organizationId);
            $byCga[] = [
                'id' => $organizationId,
                'name' => $cga?->name,
                'sigle' => $cga?->sigle,
                'tax_paid_total' => $values['tax_paid_total'],
                'active_members' => $values['active_members'],
                'declared_revenue' => $values['declared_revenue'],
            ];
        }
        usort($byCga, static fn (array $a, array $b): int => [$b['tax_paid_total'], (string) $a['name']] <=> [$a['tax_paid_total'], (string) $b['name']]);
        $natures = $this->taxByNature($period->from, $until);

        return [
            'period' => $period->toArray(),
            'coverage' => $this->coverage->forPeriod($period->from, $until),
            'computed_at' => $this->computedAt(),
            'macro' => $this->macro->forYear($until->year),
            'tax_revenue' => ['total' => (int) array_sum($natures), 'by_nature' => $natures],
            'by_cga' => $byCga,
            'by_regime' => [
                'RME' => (int) array_sum(array_column($byOrganization, 'members_rme')),
                'TEE' => (int) array_sum(array_column($byOrganization, 'members_tee')),
                'RSI' => (int) array_sum(array_column($byOrganization, 'members_rsi')),
            ],
            'by_sector' => $this->bySector($period->from, $until),
            'by_directorate' => $this->byDirectorate($byOrganization),
        ];
    }

    /**
     * Carte économique par Direction régionale (bulles : chiffre d'affaires, CGA, adhérents).
     *
     * @return array<string, mixed>
     */
    public function map(StatPeriod $period, CarbonImmutable $today): array
    {
        $until = $period->until($today);

        return [
            'period' => $period->toArray(),
            'coverage' => $this->coverage->forPeriod($period->from, $until),
            'directorates' => $this->byDirectorate($this->read(fn (): array => $this->summary->byOrganization($period->from, $until))),
        ];
    }

    /**
     * Détail par commune d'une Direction régionale : adhérents domiciliés dans ses communes.
     *
     * @return array<string, mixed>
     */
    public function communes(RegionalDirectorate $directorate, StatPeriod $period, CarbonImmutable $today): array
    {
        $until = $period->until($today);
        $counts = $this->latestCounts('commune', $period->from, $until);
        $communes = Commune::query()->where('regional_directorate_id', $directorate->id)->orderBy('name')->get(['id', 'name']);

        return [
            'period' => $period->toArray(),
            'directorate' => ['id' => $directorate->id, 'code' => $directorate->code, 'name' => $directorate->name, 'geo_key' => $directorate->geo_key],
            'communes' => $communes->map(static fn (Commune $commune): array => ['id' => $commune->id, 'name' => $commune->name, 'members' => $counts[$commune->id] ?? 0])->values()->all(),
        ];
    }

    /**
     * Impôts et cotisations payés confirmés de la période, par nature.
     *
     * @return array<string, int>
     */
    private function taxByNature(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->read(static fn () => DB::select(
            'select nature.key as nature, sum((nature.value)::bigint) as total from stat_org_daily cross join lateral jsonb_each_text(stat_org_daily.tax_paid_by_nature) as nature(key, value) where stat_org_daily.day between ? and ? group by nature.key order by nature.key',
            [$from->toDateString(), $to->toDateString()],
        ));
        $natures = [];
        foreach ($rows as $row) {
            $natures[(string) $row->nature] = (int) $row->total;
        }

        return $natures;
    }

    /**
     * Adhérents par secteur à la fin de la période, avec le libellé du secteur.
     *
     * @return list<array{id: string|null, name: string|null, members: int}>
     */
    private function bySector(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = $this->latestCounts('sector', $from, $to);
        $sectors = Sector::query()->whereIn('id', array_keys($counts))->pluck('name', 'id');
        $result = [];
        foreach ($counts as $key => $members) {
            $result[] = ['id' => $key === self::UNSPECIFIED ? null : $key, 'name' => $key === self::UNSPECIFIED ? null : $sectors->get($key), 'members' => $members];
        }
        usort($result, static fn (array $a, array $b): int => [$b['members'], (string) $a['name']] <=> [$a['members'], (string) $b['name']]);

        return $result;
    }

    /**
     * Répartition (secteur ou commune) du dernier jour calculé de la période de chaque CGA, sommée sur le réseau.
     *
     * @param  'sector'|'commune'  $dimension
     * @return array<string, int>
     */
    private function latestCounts(string $dimension, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sql = match ($dimension) {
            'sector' => 'select entry.key as key, sum((entry.value)::int) as total from (select distinct on (organization_id) members_by_sector as counts from stat_org_daily where day between ? and ? order by organization_id, day desc) latest cross join lateral jsonb_each_text(latest.counts) as entry(key, value) group by entry.key',
            'commune' => 'select entry.key as key, sum((entry.value)::int) as total from (select distinct on (organization_id) members_by_commune as counts from stat_org_daily where day between ? and ? order by organization_id, day desc) latest cross join lateral jsonb_each_text(latest.counts) as entry(key, value) group by entry.key',
        };
        $rows = $this->read(static fn () => DB::select($sql, [$from->toDateString(), $to->toDateString()]));
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->key] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Chiffres par Direction régionale des CGA (toutes les DR actives, puis les CGA sans DR).
     *
     * @param  array<string, array<string, int|float|null>>  $byOrganization
     * @return list<array{id: string|null, code: string|null, name: string|null, geo_key: string|null, cgas: int, members: int, revenue: int}>
     */
    private function byDirectorate(array $byOrganization): array
    {
        $locations = Organization::query()->whereIn('id', array_keys($byOrganization))->pluck('regional_directorate_id', 'id');
        $totals = [];
        foreach ($byOrganization as $organizationId => $values) {
            $key = $locations->get($organizationId) ?? self::UNSPECIFIED;
            $totals[$key] ??= ['cgas' => 0, 'members' => 0, 'revenue' => 0];
            $totals[$key]['cgas']++;
            $totals[$key]['members'] += (int) $values['active_members'];
            $totals[$key]['revenue'] += (int) $values['declared_revenue'];
        }

        $located = array_values(array_diff(array_keys($totals), [self::UNSPECIFIED]));
        $result = RegionalDirectorate::query()
            ->where(static fn ($query) => $query->where('active', true)->orWhereIn('id', $located))
            ->orderBy('name')
            ->get()
            ->map(static fn (RegionalDirectorate $directorate): array => [
                'id' => $directorate->id,
                'code' => $directorate->code,
                'name' => $directorate->name,
                'geo_key' => $directorate->geo_key,
                ...($totals[$directorate->id] ?? ['cgas' => 0, 'members' => 0, 'revenue' => 0]),
            ])
            ->values()
            ->all();
        if (isset($totals[self::UNSPECIFIED])) {
            $result[] = ['id' => null, 'code' => null, 'name' => null, 'geo_key' => null, ...$totals[self::UNSPECIFIED]];
        }

        return $result;
    }

    private function computedAt(): ?string
    {
        $computed = $this->read(static fn () => StatOrgDaily::query()->max('computed_at'));

        return $computed === null ? null : CarbonImmutable::parse((string) $computed)->toIso8601String();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function read(callable $read): mixed
    {
        return Tenancy::bypass($read, 'Statistiques nationales : agrégats du réseau (F-STA-01).');
    }
}
