<?php

namespace App\Domain\Statistics\Support;

use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Statistics\Models\StatOrgDaily;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Couverture des statistiques (RG-STA-01, 02-TRD §9) : « Sur la base de x CGA abonnés sur n ». Le total est le
 * registre des CGA du réseau (hors CGA radiés du réseau), jamais un nombre écrit dans le code ; les CGA abonnés
 * (essai ou abonnement en cours) sont ceux dont les agrégats économiques sont calculés (RG-STA-02).
 */
final class StatsCoverage
{
    /**
     * CGA inscrits au registre du réseau.
     *
     * @return Builder<Organization>
     */
    public static function registeredCgas(): Builder
    {
        return Organization::query()
            ->where('type', OrganizationType::Cga)
            ->where('status', '<>', OrganizationStatus::Terminated);
    }

    /**
     * CGA abonnés à la plateforme (essai ou abonnement en cours).
     *
     * @return Builder<Organization>
     */
    public static function subscribedCgas(): Builder
    {
        return self::registeredCgas()->whereIn('subscription_status', [SubscriptionStatus::Trial, SubscriptionStatus::Active]);
    }

    /**
     * Couverture du jour.
     *
     * @return array{subscribed_cgas: int, total_cgas: int, label: string}
     */
    public function current(): array
    {
        return $this->coverage(self::subscribedCgas()->count());
    }

    /**
     * Couverture d'une période : CGA dont les agrégats figurent dans la période, sur le registre.
     *
     * @return array{subscribed_cgas: int, total_cgas: int, label: string}
     */
    public function forPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $contributors = Tenancy::bypass(
            static fn (): int => StatOrgDaily::query()->whereBetween('day', [$from->toDateString(), $to->toDateString()])->distinct()->count('organization_id'),
            'Statistiques : couverture du réseau (RG-STA-01).',
        );

        return $this->coverage($contributors);
    }

    /**
     * @return array{subscribed_cgas: int, total_cgas: int, label: string}
     */
    private function coverage(int $subscribed): array
    {
        $total = self::registeredCgas()->count();

        return [
            'subscribed_cgas' => $subscribed,
            'total_cgas' => $total,
            'label' => trans_choice('statistics.coverage', $subscribed, ['subscribed' => $subscribed, 'total' => $total]),
        ];
    }
}
