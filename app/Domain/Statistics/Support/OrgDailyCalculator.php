<?php

namespace App\Domain\Statistics\Support;

use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Enums\RevenueKind;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRegimeChange;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Members\Models\Radiation;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Reports\Support\PortfolioStates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Agrégats journaliers d'un CGA (stat_org_daily, 02-TRD §9) sur une plage de jours d'une même année, portefeuille
 * CGA seulement (RG-STA-05) : un dossier y compte les jours où son contrat CGA est en vigueur, selon la règle de
 * Member::regulatoryAt (décision 0007), avec son régime du jour (historique des régimes, comme les états du
 * rapport trimestriel).
 *
 * - Stocks du jour : dossiers du portefeuille CGA, par régime (RSI et RNI : reclassés en attente de radiation),
 *   par secteur et par commune (actuels), chiffre d'affaires de référence de l'année (réalisé, à défaut
 *   prévisionnel ; une valeur Compta Flow remplace la saisie, RG-DATA-06).
 * - Flux du jour : adhésions (entrée au portefeuille), radiations validées non annulées (dossier au portefeuille
 *   la veille de l'effet), honoraires échus et encaissés (part des paiements confirmés affectée aux honoraires),
 *   impôts et cotisations payés confirmés par nature, préoccupations d'adhérents ouvertes, closes et premières
 *   réponses (délai moyen en heures).
 *
 * Aucune donnée nominative ne sort : seulement des nombres, des montants et des identifiants de référentiels.
 * Lit des données cloisonnées : l'appelant ouvre le contournement.
 */
final class OrgDailyCalculator
{
    private const SECONDS_PER_HOUR = 3600;

    private const HOURS_ROUNDING = 2;

    private const UNSPECIFIED = 'none';

    private CarbonImmutable $from;

    private int $days;

    /** @var array<string, array{start: CarbonImmutable, end: CarbonImmutable|null}> */
    private array $intervals = [];

    public function __construct(private readonly PortfolioStates $states) {}

    /**
     * @return list<array<string, mixed>> une ligne par jour, prête à écrire (jsonb encodés)
     */
    public function compute(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, CarbonInterface $now): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        if ($from->year !== $to->year || $from->greaterThan($to)) {
            throw new InvalidArgumentException('La plage des agrégats tient dans une seule année.');
        }
        $this->from = $from;
        $this->days = $this->offset($to) + 1;
        $this->intervals = [];

        $members = $this->members($organizationId, $from, $to);
        $stocks = $this->stocks($members, $to);
        $flows = [
            'new_members' => $this->newMembers($members, $to),
            'radiations' => $this->radiations($organizationId, $to),
            ...$this->fees($organizationId, $to),
        ];
        $taxes = $this->taxes($organizationId, $to);
        $concerns = $this->concerns($organizationId, $to);

        $rows = [];
        for ($index = 0; $index < $this->days; $index++) {
            $responded = $concerns['responded'][$index];
            $rows[] = [
                'organization_id' => $organizationId,
                'day' => $from->addDays($index)->toDateString(),
                'active_members' => $stocks['members_rme'][$index] + $stocks['members_tee'][$index] + $stocks['members_rsi'][$index],
                'members_rme' => $stocks['members_rme'][$index],
                'members_tee' => $stocks['members_tee'][$index],
                'members_rsi' => $stocks['members_rsi'][$index],
                'new_members' => $flows['new_members'][$index],
                'radiations' => $flows['radiations'][$index],
                'fees_due' => $flows['fees_due'][$index],
                'fees_paid' => $flows['fees_paid'][$index],
                'tax_paid_total' => (int) array_sum($taxes[$index]),
                'tax_paid_by_nature' => json_encode($taxes[$index], JSON_THROW_ON_ERROR),
                'declared_revenue' => $stocks['declared_revenue'][$index],
                'members_by_sector' => json_encode((object) $stocks['by_sector'][$index], JSON_THROW_ON_ERROR),
                'members_by_commune' => json_encode((object) $stocks['by_commune'][$index], JSON_THROW_ON_ERROR),
                'concerns_opened' => $concerns['opened'][$index],
                'concerns_closed' => $concerns['closed'][$index],
                'concerns_responded' => $responded,
                'concerns_avg_response_hours' => $responded > 0 ? round($concerns['seconds'][$index] / $responded / self::SECONDS_PER_HOUR, self::HOURS_ROUNDING) : null,
                'computed_at' => $now->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Dossiers ayant un contrat CGA en vigueur au moins un jour de la plage, ou la veille (radiations du premier
     * jour), avec leur historique de régimes et leur chiffre d'affaires de l'année.
     *
     * @return Collection<int, Member>
     */
    private function members(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $members = Member::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('cga_contract_signed_on')
            ->whereNotNull('adhesion_date')
            ->where('adhesion_date', '<=', $to->toDateString())
            ->where(static fn (Builder $contract) => $contract
                ->where(static fn (Builder $current) => $current->where('portfolio', Portfolio::Cga)->whereNull('cga_contract_ended_on'))
                ->orWhere('cga_contract_ended_on', '>=', $from->subDay()->toDateString()))
            ->with(['regimeHistory', 'revenues' => static fn ($revenues) => $revenues->where('year', $from->year)])
            ->get(['id', 'organization_id', 'portfolio', 'regime', 'sector_id', 'commune_id', 'adhesion_date', 'cga_contract_signed_on', 'cga_contract_ended_on']);

        foreach ($members as $member) {
            /** @var CarbonImmutable $start */
            $start = $member->adhesion_date;
            /** @var CarbonImmutable|null $end */
            $end = $member->cga_contract_ended_on;
            $this->intervals[$member->id] = ['start' => $start, 'end' => $end];
        }

        return $members;
    }

    /**
     * Dossier au portefeuille CGA ce jour-là (Member::regulatoryAt).
     */
    private function inPortfolio(string $memberId, CarbonImmutable $day): bool
    {
        $interval = $this->intervals[$memberId] ?? null;

        return $interval !== null
            && $interval['start']->lessThanOrEqualTo($day)
            && ($interval['end'] === null || $interval['end']->greaterThan($day));
    }

    /**
     * Rang d'un jour dans la plage, ou null s'il en sort.
     */
    private function index(CarbonInterface $day): ?int
    {
        $index = $this->offset(CarbonImmutable::parse($day->toDateString()));

        return $index >= 0 && $index < $this->days ? $index : null;
    }

    /**
     * Écart signé en jours entiers entre le premier jour de la plage et un jour.
     */
    private function offset(CarbonInterface $day): int
    {
        return (int) round($this->from->diffInDays($day->copy()->startOfDay(), false));
    }

    /**
     * @return list<int>
     */
    private function zeros(): array
    {
        return array_fill(0, $this->days, 0);
    }

    /**
     * Stocks du jour par différences : +n à l'entrée, -n à la sortie, puis cumul.
     *
     * @param  Collection<int, Member>  $members
     * @return array{members_rme: list<int>, members_tee: list<int>, members_rsi: list<int>, declared_revenue: list<int>, by_sector: list<array<string, int>>, by_commune: list<array<string, int>>}
     */
    private function stocks(Collection $members, CarbonImmutable $to): array
    {
        $diff = ['members_rme' => $this->zeros(), 'members_tee' => $this->zeros(), 'members_rsi' => $this->zeros(), 'declared_revenue' => $this->zeros()];
        $diff = array_map(static fn (array $values): array => [...$values, 0], $diff);
        /** @var array{sector: array<string, array<int, int>>, commune: array<string, array<int, int>>} $keyed */
        $keyed = ['sector' => [], 'commune' => []];

        foreach ($members as $member) {
            $interval = $this->intervals[$member->id];
            $start = $interval['start']->greaterThan($this->from) ? $interval['start'] : $this->from;
            $end = $interval['end'] !== null && $interval['end']->lessThanOrEqualTo($to) ? $interval['end'] : $to->addDay();
            if ($start->greaterThanOrEqualTo($end)) {
                continue;
            }
            $first = $this->offset($start);
            $last = $this->offset($end);

            foreach ($this->regimeSegments($member, $start, $end) as [$segmentStart, $segmentEnd, $regime]) {
                $column = match ($regime) {
                    TaxRegime::Rme => 'members_rme',
                    TaxRegime::Tee => 'members_tee',
                    TaxRegime::Rsi, TaxRegime::Rni => 'members_rsi',
                };
                $diff[$column][$segmentStart]++;
                $diff[$column][$segmentEnd]--;
            }
            $revenue = $this->revenue($member);
            $diff['declared_revenue'][$first] += $revenue;
            $diff['declared_revenue'][$last] -= $revenue;
            foreach (['sector' => $member->sector_id, 'commune' => $member->commune_id] as $dimension => $key) {
                $key ??= self::UNSPECIFIED;
                $keyed[$dimension][$key][$first] = ($keyed[$dimension][$key][$first] ?? 0) + 1;
                $keyed[$dimension][$key][$last] = ($keyed[$dimension][$key][$last] ?? 0) - 1;
            }
        }

        $stocks = array_map(fn (array $values): array => $this->cumulate($values), $diff);

        return [
            'members_rme' => $stocks['members_rme'],
            'members_tee' => $stocks['members_tee'],
            'members_rsi' => $stocks['members_rsi'],
            'declared_revenue' => $stocks['declared_revenue'],
            'by_sector' => $this->cumulateKeyed($keyed['sector']),
            'by_commune' => $this->cumulateKeyed($keyed['commune']),
        ];
    }

    /**
     * Périodes de régime d'un dossier dans [start, end[, en rangs de la plage (règle de PortfolioStates::regimeOn).
     *
     * @return list<array{int, int, TaxRegime}>
     */
    private function regimeSegments(Member $member, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $segments = [];
        $segmentStart = $start;
        $regime = $this->states->regimeOn($member, $start);
        $changes = $member->regimeHistory
            ->sortBy(static fn (MemberRegimeChange $change): string => $change->effective_date->toDateString().$change->id)
            ->filter(static fn (MemberRegimeChange $change): bool => $change->effective_date->greaterThan($start) && $change->effective_date->lessThan($end));
        foreach ($changes as $change) {
            $changeDay = CarbonImmutable::parse($change->effective_date->toDateString());
            if ($change->regime !== $regime) {
                $segments[] = [$this->offset($segmentStart), $this->offset($changeDay), $regime];
                $segmentStart = $changeDay;
                $regime = $change->regime;
            }
        }
        $segments[] = [$this->offset($segmentStart), $this->offset($end), $regime];

        return $segments;
    }

    /**
     * Chiffre d'affaires de référence de l'année du dossier : le réalisé, à défaut le prévisionnel.
     */
    private function revenue(Member $member): int
    {
        $revenue = $member->revenues
            ->sortBy(static fn (MemberRevenue $revenue): int => $revenue->kind === RevenueKind::Actual ? 0 : 1)
            ->first();

        return (int) $revenue?->amount;
    }

    /**
     * @param  list<int>  $diff
     * @return list<int>
     */
    private function cumulate(array $diff): array
    {
        $values = [];
        $running = 0;
        for ($index = 0; $index < $this->days; $index++) {
            $running += $diff[$index];
            $values[] = $running;
        }

        return $values;
    }

    /**
     * @param  array<string, array<int, int>>  $diffs
     * @return list<array<string, int>>
     */
    private function cumulateKeyed(array $diffs): array
    {
        ksort($diffs);
        $values = array_fill(0, $this->days, []);
        foreach ($diffs as $key => $diff) {
            $running = 0;
            for ($index = 0; $index < $this->days; $index++) {
                $running += $diff[$index] ?? 0;
                if ($running > 0) {
                    $values[$index][(string) $key] = $running;
                }
            }
        }

        return $values;
    }

    /**
     * Entrées au portefeuille CGA : la date d'adhésion d'un dossier de la plage.
     *
     * @param  Collection<int, Member>  $members
     * @return list<int>
     */
    private function newMembers(Collection $members, CarbonImmutable $to): array
    {
        $values = $this->zeros();
        foreach ($members as $member) {
            /** @var CarbonImmutable $adhesion */
            $adhesion = $member->adhesion_date;
            $index = $this->index($adhesion);
            if ($index !== null && $adhesion->lessThanOrEqualTo($to) && $this->inPortfolio($member->id, $adhesion)) {
                $values[$index]++;
            }
        }

        return $values;
    }

    /**
     * Radiations validées et non annulées prenant effet dans la plage, d'un dossier au portefeuille CGA la veille.
     *
     * @return list<int>
     */
    private function radiations(string $organizationId, CarbonImmutable $to): array
    {
        $values = $this->zeros();
        Radiation::query()
            ->where('organization_id', $organizationId)
            ->where('status', RadiationStatus::Validated)
            ->whereNull('cancelled_at')
            ->whereBetween('effective_date', [$this->from->toDateString(), $to->toDateString()])
            ->get(['member_id', 'effective_date'])
            ->each(function (Radiation $radiation) use (&$values): void {
                $effect = CarbonImmutable::parse($radiation->effective_date->toDateString());
                $index = $this->index($effect);
                if ($index !== null && $this->inPortfolio($radiation->member_id, $effect->subDay())) {
                    $values[$index]++;
                }
            });

        return $values;
    }

    /**
     * Honoraires échus (charges d'honoraires non annulées, par date d'échéance) et encaissés (part des paiements
     * confirmés affectée aux honoraires, par date de paiement), d'un dossier au portefeuille CGA ce jour-là.
     *
     * @return array{fees_due: list<int>, fees_paid: list<int>}
     */
    private function fees(string $organizationId, CarbonImmutable $to): array
    {
        $range = [$this->from->toDateString(), $to->toDateString()];
        $due = MemberCharge::query()
            ->where('organization_id', $organizationId)
            ->where('kind', ChargeKind::Fees)
            ->where('status', '<>', ChargeStatus::Cancelled)
            ->whereBetween('due_date', $range)
            ->groupBy('member_id', 'due_date')
            ->select(['member_id', 'due_date'])
            ->selectRaw('sum(amount) as total')
            ->toBase()
            ->get();
        $paid = PaymentAllocation::query()
            ->join('member_payments', 'member_payments.id', '=', 'member_payment_allocations.payment_id')
            ->join('member_charges', 'member_charges.id', '=', 'member_payment_allocations.charge_id')
            ->where('member_payments.organization_id', $organizationId)
            ->where('member_payments.status', PaymentStatus::Confirmed)
            ->where('member_charges.kind', ChargeKind::Fees)
            ->whereBetween('member_payments.paid_on', $range)
            ->groupBy('member_payments.member_id', 'member_payments.paid_on')
            ->select(['member_payments.member_id as member_id', 'member_payments.paid_on as day'])
            ->selectRaw('sum(member_payment_allocations.amount) as total')
            ->toBase()
            ->get();

        return [
            'fees_due' => $this->spread($due, 'due_date'),
            'fees_paid' => $this->spread($paid, 'day'),
        ];
    }

    /**
     * Montants groupés (member_id, jour, total) répartis par jour, pour les dossiers au portefeuille CGA ce jour-là.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<int>
     */
    private function spread(Collection $rows, string $dayColumn): array
    {
        $values = $this->zeros();
        foreach ($rows as $row) {
            $day = CarbonImmutable::parse((string) $row->{$dayColumn});
            $index = $this->index($day);
            if ($index !== null && $this->inPortfolio((string) $row->member_id, $day)) {
                $values[$index] += (int) $row->total;
            }
        }

        return $values;
    }

    /**
     * Impôts et cotisations payés confirmés, par jour de paiement et par nature (toutes les natures, à zéro par
     * défaut), d'un dossier au portefeuille CGA ce jour-là.
     *
     * @return list<array<string, int>>
     */
    private function taxes(string $organizationId, CarbonImmutable $to): array
    {
        $empty = array_fill_keys(array_map(static fn (TaxNature $nature): string => $nature->value, TaxNature::cases()), 0);
        $values = array_fill(0, $this->days, $empty);
        ObligationPayment::query()
            ->where('organization_id', $organizationId)
            ->where('status', PaymentStatus::Confirmed)
            ->whereBetween('paid_on', [$this->from->toDateString(), $to->toDateString()])
            ->groupBy('member_id', 'paid_on', 'tax_nature')
            ->select(['member_id', 'paid_on', 'tax_nature'])
            ->selectRaw('sum(amount) as total')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$values): void {
                $day = CarbonImmutable::parse((string) $row->paid_on);
                $index = $this->index($day);
                if ($index !== null && $this->inPortfolio((string) $row->member_id, $day)) {
                    $values[$index][(string) $row->tax_nature] = ($values[$index][(string) $row->tax_nature] ?? 0) + (int) $row->total;
                }
            });

        return $values;
    }

    /**
     * Préoccupations d'adhérents (niveau adhérent → CGA) d'un dossier au portefeuille CGA le jour de la demande :
     * ouvertes (jour de la demande), closes (traitées ou rejetées, jour de clôture), premières réponses (jour de la
     * réponse, délai en secondes).
     *
     * @return array{opened: array<int, int>, closed: array<int, int>, responded: array<int, int>, seconds: array<int, int>}
     */
    private function concerns(string $organizationId, CarbonImmutable $to): array
    {
        $values = ['opened' => $this->zeros(), 'closed' => $this->zeros(), 'responded' => $this->zeros(), 'seconds' => $this->zeros()];
        $start = $this->from->startOfDay();
        $end = $to->endOfDay();
        Concern::query()
            ->where('organization_id', $organizationId)
            ->where('level', ConcernLevel::MemberToCga)
            ->whereNotNull('member_id')
            ->where(static fn (Builder $period) => $period
                ->whereBetween('created_at', [$start, $end])
                ->orWhereBetween('resolved_at', [$start, $end])
                ->orWhereBetween('first_response_at', [$start, $end]))
            ->get(['member_id', 'status', 'created_at', 'resolved_at', 'first_response_at'])
            ->each(function (Concern $concern) use (&$values): void {
                $created = CarbonImmutable::parse($concern->created_at);
                if (! $this->inPortfolio((string) $concern->member_id, $created->startOfDay())) {
                    return;
                }
                $this->count($values['opened'], $created);
                if ($concern->status->isClosed() && $concern->resolved_at !== null) {
                    $this->count($values['closed'], CarbonImmutable::parse($concern->resolved_at));
                }
                if ($concern->first_response_at !== null) {
                    $response = CarbonImmutable::parse($concern->first_response_at);
                    $index = $this->index($response);
                    if ($index !== null) {
                        $values['responded'][$index]++;
                        $values['seconds'][$index] += max(0, (int) $created->diffInSeconds($response));
                    }
                }
            });

        return $values;
    }

    /**
     * @param  array<int, int>  $values
     */
    private function count(array &$values, CarbonImmutable $moment): void
    {
        $index = $this->index($moment);
        if ($index !== null) {
            $values[$index]++;
        }
    }
}
