<?php

namespace App\Domain\Statistics\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Statistics\Support\StatsCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recalcul de nuit des statistiques (stats:refresh, 02-TRD §9 et §11) : agrégats journaliers de l'année de chaque
 * CGA abonné (les CGA non abonnés n'ont pas de statistiques économiques, RG-STA-02), puis la vue mensuelle du
 * réseau, sur tous les CGA. Un CGA désigné seul ne recalcule que ses agrégats.
 */
final class RefreshNetworkStats
{
    public function __construct(private readonly RefreshOrganizationStats $organization) {}

    /**
     * @return array{organizations: int, days: int}
     */
    public function execute(int $year, CarbonImmutable $now, ?string $organizationId = null): array
    {
        $organizations = 0;
        $days = 0;
        StatsCoverage::subscribedCgas()
            ->when($organizationId !== null, static fn ($query) => $query->whereKey($organizationId))
            ->orderBy('id')
            ->each(function (Organization $cga) use ($year, $now, &$organizations, &$days): void {
                $days += $this->organization->execute($cga, $year, $now);
                $organizations++;
            });

        if ($organizationId === null) {
            DB::statement('REFRESH MATERIALIZED VIEW stat_network_monthly');
        }

        return ['organizations' => $organizations, 'days' => $days];
    }
}
