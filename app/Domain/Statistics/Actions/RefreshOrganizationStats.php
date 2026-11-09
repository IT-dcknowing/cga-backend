<?php

namespace App\Domain\Statistics\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Statistics\Models\StatOrgDaily;
use App\Domain\Statistics\Support\OrgDailyCalculator;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recalcul des agrégats journaliers d'un CGA pour une année (02-TRD §9) : du 1er janvier au jour même pour
 * l'année en cours, à toute l'année pour une année passée, rien pour une année à venir. Les lignes de la plage
 * sont remplacées d'un bloc, dans une transaction ; écrit hors espace actif, par un contournement nommé.
 */
final class RefreshOrganizationStats
{
    public function __construct(private readonly OrgDailyCalculator $calculator) {}

    /**
     * @return int jours calculés
     */
    public function execute(Organization $cga, int $year, CarbonImmutable $now): int
    {
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $yearEnd = $from->endOfYear()->startOfDay();
        $today = $now->startOfDay();
        $to = $yearEnd->lessThan($today) ? $yearEnd : $today;
        if ($from->greaterThan($to)) {
            return 0;
        }

        return Tenancy::bypass(fn (): int => DB::transaction(function () use ($cga, $from, $to, $now): int {
            $rows = $this->calculator->compute($cga->id, $from, $to, $now);
            StatOrgDaily::query()->upsert($rows, ['organization_id', 'day'], array_values(array_diff(array_keys($rows[0]), ['organization_id', 'day'])));

            return count($rows);
        }), 'Statistiques : recalcul des agrégats journaliers du CGA.');
    }
}
