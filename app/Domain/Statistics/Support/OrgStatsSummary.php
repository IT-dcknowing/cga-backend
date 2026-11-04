<?php

namespace App\Domain\Statistics\Support;

use App\Domain\Statistics\Models\StatOrgDaily;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Indicateurs d'une période par CGA, lus dans stat_org_daily (02-TRD §9 : la période est une dimension des
 * agrégats) : les flux sont sommés sur les jours de la période, les stocks sont ceux du dernier jour calculé de
 * la période ; le délai moyen de première réponse est pondéré par le nombre de réponses (jamais une moyenne de
 * moyennes). Lit dans l'espace actif : un CGA ne voit que ses lignes, le CNCGA lit par un contournement nommé.
 */
final class OrgStatsSummary
{
    private const HOURS_ROUNDING = 2;

    /**
     * @param  list<string>|null  $organizationIds  null : tous les CGA visibles
     * @return array<string, array<string, int|float|null>> CGA => indicateurs
     */
    public function byOrganization(CarbonImmutable $from, CarbonImmutable $to, ?array $organizationIds = null): array
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $scope = static fn (Builder $query): Builder => $query
            ->whereBetween('day', $range)
            ->when($organizationIds !== null, static fn (Builder $only) => $only->whereIn('organization_id', (array) $organizationIds));

        $flows = $scope(StatOrgDaily::query())
            ->groupBy('organization_id')
            ->select('organization_id')
            ->selectRaw('sum(new_members) as new_members, sum(radiations) as radiations, sum(fees_due) as fees_due, sum(fees_paid) as fees_paid')
            ->selectRaw('sum(tax_paid_total) as tax_paid_total, sum(concerns_opened) as concerns_opened, sum(concerns_closed) as concerns_closed')
            ->selectRaw('sum(concerns_responded) as concerns_responded, sum(concerns_avg_response_hours * concerns_responded) as response_hours')
            ->toBase()
            ->get();
        $stocks = $scope(StatOrgDaily::query())
            ->selectRaw('distinct on (organization_id) organization_id, day, active_members, members_rme, members_tee, members_rsi, declared_revenue')
            ->orderBy('organization_id')
            ->orderByDesc('day')
            ->toBase()
            ->get()
            ->keyBy('organization_id');

        $result = [];
        foreach ($flows as $row) {
            $organizationId = (string) $row->organization_id;
            $stock = $stocks->get($organizationId);
            $responded = (int) $row->concerns_responded;
            $values = [];
            foreach (StatOrgDaily::STOCKS as $column) {
                $values[$column] = (int) ($stock->{$column} ?? 0);
            }
            foreach (StatOrgDaily::FLOWS as $column) {
                $values[$column] = (int) $row->{$column};
            }
            $values['concerns_avg_response_hours'] = $responded > 0 ? round((float) $row->response_hours / $responded, self::HOURS_ROUNDING) : null;
            $result[$organizationId] = $values;
        }
        ksort($result);

        return $result;
    }
}
