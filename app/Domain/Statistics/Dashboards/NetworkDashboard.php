<?php

namespace App\Domain\Statistics\Dashboards;

use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Dues\Enums\DuesStatus;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Dues\Models\NetworkDuePayment;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Statistics\Models\StatNetworkMonthly;
use App\Domain\Statistics\Models\StatOrgDaily;
use App\Domain\Statistics\Support\OrgStatsSummary;
use App\Domain\Statistics\Support\StatPeriod;
use App\Domain\Statistics\Support\StatsCoverage;
use App\Domain\Statistics\Support\Variation;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Tableau de bord du CNCGA (F-DSH-01, 03-APPFLOW §3.2) pour une période (F-DSH-04) : CGA abonnés sur le registre,
 * cotisations recouvrées (période, N-1, part de l'attendu de l'année), préoccupations ouvertes des CGA au CNCGA,
 * adhérents du réseau (portefeuille CGA des CGA abonnés, N-1), graphique mensuel des cotisations de l'année et
 * alertes (rapports en retard, CGA impayés, agréments à échéance, formations presque complètes). Chaque bloc
 * n'est rendu qu'avec le droit qui ouvre l'écran correspondant. Aucune donnée nominative d'adhérent : les
 * adhérents sont lus dans les agrégats (02-TRD §9) ; les CGA sont nommés seulement dans les alertes.
 */
final class NetworkDashboard
{
    public function __construct(
        private readonly OrgStatsSummary $summary,
        private readonly StatsCoverage $coverage,
        private readonly SettingsService $settings,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(StatPeriod $period, CarbonImmutable $today): array
    {
        $previous = $period->previousYear();
        $indicators = ['cgas' => $this->coverage->current()];
        if ($this->context->allows('dues.view')) {
            $indicators['dues'] = $this->dues($period, $previous, $today);
        }
        if ($this->context->allows('network_concerns.view')) {
            $indicators['concerns'] = $this->concerns($period, $previous, $today);
        }
        if ($this->context->allows('statistics.national.view')) {
            $indicators['members'] = Variation::of($this->members($period, $today), $this->members($previous, $today));
        }

        return [
            'period' => $period->toArray(),
            'coverage' => $this->coverage->current(),
            'computed_at' => $this->computedAt(),
            'indicators' => $indicators,
            'dues_chart' => $this->context->allows('dues.view') ? $this->duesChart($period->until($today)->year) : null,
            'alerts' => $this->alerts($today),
        ];
    }

    /**
     * Cotisations recouvrées dans la période (paiements confirmés), N-1, et recouvrement de l'année à la fin de la
     * période sur le montant attendu de l'année.
     *
     * @return array<string, mixed>
     */
    private function dues(StatPeriod $period, StatPeriod $previous, CarbonImmutable $today): array
    {
        $collected = fn (StatPeriod $range): int => (int) $this->network(fn () => NetworkDuePayment::query()
            ->where('status', PaymentStatus::Confirmed)
            ->whereBetween('paid_on', [$range->from->toDateString(), $range->until($today)->toDateString()])
            ->sum('amount'));
        $end = $period->until($today);
        $year = $end->year;
        $expected = (int) $this->network(static fn () => NetworkDue::query()->where('year', $year)->sum('amount_due'));
        $yearToDate = (int) $this->network(static fn () => NetworkDuePayment::query()
            ->where('status', PaymentStatus::Confirmed)
            ->where('paid_on', '<=', $end->toDateString())
            ->whereHas('due', static fn ($due) => $due->where('year', $year))
            ->sum('amount'));

        return [
            'collected' => Variation::of($collected($period), $collected($previous)),
            'year' => $year,
            'expected' => $expected,
            'collected_year_to_date' => $yearToDate,
            'rate' => $expected > 0 ? round($yearToDate / $expected, 3) : null,
        ];
    }

    /**
     * Préoccupations des CGA au CNCGA : ouvertes ou en cours aujourd'hui ; déposées dans la période et en N-1.
     *
     * @return array<string, mixed>
     */
    private function concerns(StatPeriod $period, StatPeriod $previous, CarbonImmutable $today): array
    {
        $opened = fn (StatPeriod $range): int => (int) $this->network(static fn () => Concern::query()
            ->where('level', ConcernLevel::CgaToCncga)
            ->whereBetween('created_at', [$range->from->startOfDay(), $range->until($today)->endOfDay()])
            ->count());

        return [
            'open' => (int) $this->network(static fn () => Concern::query()
                ->where('level', ConcernLevel::CgaToCncga)
                ->whereIn('status', [ConcernStatus::Open, ConcernStatus::InReview])
                ->count()),
            'submitted' => Variation::of($opened($period), $opened($previous)),
        ];
    }

    /**
     * Adhérents du portefeuille CGA des CGA abonnés à la fin de la période (agrégats).
     */
    private function members(StatPeriod $period, CarbonImmutable $today): int
    {
        $byOrganization = $this->network(fn (): array => $this->summary->byOrganization($period->from, $period->until($today)));

        return (int) array_sum(array_column($byOrganization, 'active_members'));
    }

    /**
     * Cotisations de l'année, mois par mois (vue stat_network_monthly).
     *
     * @return list<array{month: string, collected: int, collected_year_to_date: int, expected: int}>
     */
    private function duesChart(int $year): array
    {
        return StatNetworkMonthly::query()
            ->whereBetween('month', [CarbonImmutable::create($year, 1, 1)->toDateString(), CarbonImmutable::create($year, 12, 1)->toDateString()])
            ->orderBy('month')
            ->get()
            ->map(static fn (StatNetworkMonthly $month): array => [
                'month' => $month->month->toDateString(),
                'collected' => $month->dues_collected,
                'collected_year_to_date' => $month->dues_collected_year_to_date,
                'expected' => $month->dues_expected,
            ])
            ->values()
            ->all();
    }

    /**
     * Alertes cliquables (03-APPFLOW §3.2), selon les droits.
     *
     * @return list<array{code: string, count: int, label: string, items?: list<array<string, mixed>>}>
     */
    private function alerts(CarbonImmutable $today): array
    {
        $alerts = [];
        if ($this->context->allows('reports.network.view')) {
            $late = (int) $this->network(static fn () => QuarterlyReport::query()
                ->where('is_late', true)
                ->whereIn('status', [ReportStatus::Draft, ReportStatus::Generated])
                ->count());
            $alerts[] = $this->alert('reports_late', $late);
        }
        if ($this->context->allows('dues.view')) {
            $unpaid = (int) $this->network(static fn () => NetworkDue::query()->where('year', $today->year)->where('status', DuesStatus::Unpaid)->count());
            $alerts[] = $this->alert('dues_unpaid', $unpaid);
        }
        if ($this->context->allows('organizations.view')) {
            $months = max([0, ...array_map('intval', (array) $this->settings->get('agreements.alert_months'))]);
            $expiring = StatsCoverage::registeredCgas()
                ->whereBetween('agreement_expires_at', [$today->toDateString(), $today->addMonthsNoOverflow($months)->toDateString()])
                ->orderBy('agreement_expires_at')
                ->get(['id', 'name', 'agreement_expires_at'])
                ->map(static fn (Organization $cga): array => ['id' => $cga->id, 'name' => $cga->name, 'expires_at' => $cga->agreement_expires_at?->toDateString()])
                ->values()
                ->all();
            $alerts[] = $this->alert('agreements_expiring', count($expiring), $expiring);
        }
        if ($this->context->allows('trainings.network.manage')) {
            $threshold = (int) $this->settings->get('trainings.almost_full_percent');
            $almostFull = Training::query()
                ->where('status', TrainingStatus::Published)
                ->where('starts_at', '>', $today)
                ->whereNotNull('capacity')
                ->where('capacity', '>', 0)
                ->orderBy('starts_at')
                ->get()
                ->filter(static fn (Training $training): bool => 100 * TrainingAccess::seatsTaken($training) >= $threshold * (int) $training->capacity)
                ->map(static fn (Training $training): array => ['id' => $training->id, 'title' => $training->title, 'starts_at' => $training->starts_at->toIso8601String(), 'seats_left' => TrainingAccess::seatsLeft($training)])
                ->values()
                ->all();
            $alerts[] = $this->alert('trainings_almost_full', count($almostFull), $almostFull);
        }

        return $alerts;
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     * @return array{code: string, count: int, label: string, items?: list<array<string, mixed>>}
     */
    private function alert(string $code, int $count, ?array $items = null): array
    {
        $alert = ['code' => $code, 'count' => $count, 'label' => trans_choice("statistics.alerts.{$code}", $count, ['count' => $count])];

        return $items === null ? $alert : [...$alert, 'items' => $items];
    }

    private function computedAt(): ?string
    {
        $computed = $this->network(static fn () => StatOrgDaily::query()->max('computed_at'));

        return $computed === null ? null : CarbonImmutable::parse((string) $computed)->toIso8601String();
    }

    /**
     * Lecture du réseau par le CNCGA : données des CGA, sans donnée nominative d'adhérent (05-BACKEND-SCHEMA §10, §11).
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function network(callable $read): mixed
    {
        return Tenancy::bypass($read, 'Tableau de bord du CNCGA : chiffres du réseau (F-DSH-01).');
    }
}
