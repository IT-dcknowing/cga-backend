<?php

namespace App\Domain\Reports\Support;

use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Enums\RevenueKind;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRegimeChange;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Members\Models\Radiation;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Referentials\Models\TaxOffice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * États du portefeuille du rapport trimestriel (PRD M09, section 5, RG-RPT-01 « zéro ressaisie »),
 * arrêtés au dernier jour du trimestre et regroupés par CDI : Suivi TEE, Suivi RME (paiements par
 * nature), Nouveaux TEE / RME, Surclassements TEE → RME et RME → RSI, Résiliations TEE / RME, TEE / RME
 * augmentées (règle RG-RPT-08), Résumé ; anomalies à corriger et sources des données (RG-RPT-12).
 *
 * Portefeuille CGA seulement (RG-RPT-11, RG-SEC-08) : les dossiers sont ceux dont le contrat CGA est en
 * vigueur à la date d'arrêté (Member::regulatoryAt, décision 0007). Le résultat est un tableau
 * sérialisable : il entre tel quel dans l'instantané d'une version (RG-RPT-05).
 */
final class PortfolioStates
{
    private CarbonImmutable $yearStart;

    private CarbonImmutable $quarterStart;

    private CarbonImmutable $end;

    private int $year;

    /** @var array<string, array{id: string, code: string, name: string}> */
    private array $offices = [];

    /**
     * @param  array{basis: string, min_increase_pct: int|float, amount: string}  $increaseRule
     * @return array<string, mixed>
     */
    public function compute(string $organizationId, int $year, int $quarter, array $increaseRule): array
    {
        $this->year = $year;
        $this->yearStart = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $this->quarterStart = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();
        $this->end = $this->quarterStart->addMonthsNoOverflow(2)->endOfMonth()->startOfDay();
        $this->offices = TaxOffice::query()->get()->mapWithKeys(static fn (TaxOffice $office): array => [
            $office->id => ['id' => $office->id, 'code' => $office->code, 'name' => $office->name],
        ])->all();

        $members = Member::query()
            ->with(['commune', 'revenues', 'regimeHistory'])
            ->where('organization_id', $organizationId)
            ->regulatoryAt($this->end)
            ->orderBy('company_name')
            ->get();
        $tee = $members->filter(fn (Member $member): bool => $this->regimeOn($member, $this->end) === TaxRegime::Tee)->values();
        $rme = $members->filter(fn (Member $member): bool => $this->regimeOn($member, $this->end) === TaxRegime::Rme)->values();

        $teeAmounts = $this->teeAmounts($tee->pluck('id')->all());
        $rmePayments = $this->paymentsByNature($rme->pluck('id')->all(), $this->quarterStart, $this->end);
        $isNew = fn (Member $member): bool => $member->adhesion_date !== null && $member->adhesion_date->betweenIncluded($this->quarterStart, $this->end);
        $terminations = $this->terminations($organizationId, $year, $quarter);

        $sheets = [
            'tee_follow_up' => $this->grouped($tee, fn (Member $member): array => [...$this->identity($member), ...$teeAmounts[$member->id]], ['annual_gross', 'annual_net', 'annual_paid', 'annual_months', 'annual_balance', 'quarter_gross', 'quarter_net', 'quarter_paid', 'quarter_months', 'quarter_balance']),
            'rme_follow_up' => $this->grouped($rme, fn (Member $member): array => [...$this->identity($member), ...$this->rmeColumns($rmePayments[$member->id] ?? [])], [...array_keys((array) config('reports.rme_columns')), 'total']),
            'new_tee' => $this->grouped($tee->filter($isNew)->values(), fn (Member $member): array => [...$this->identity($member), 'registration_date' => $member->registration_date?->toDateString()], ['reference_revenue']),
            'new_rme' => $this->grouped($rme->filter($isNew)->values(), fn (Member $member): array => $this->identity($member), ['reference_revenue']),
            'upgrade_tee_rme' => $this->upgrades($organizationId, TaxRegime::Tee, TaxRegime::Rme),
            'upgrade_rme_rsi' => $this->upgrades($organizationId, TaxRegime::Rme, TaxRegime::Rsi),
            'terminations_tee' => $terminations[TaxRegime::Tee->value],
            'terminations_rme' => $terminations[TaxRegime::Rme->value],
            'increased_tee' => $this->increased($tee, TaxRegime::Tee, $increaseRule, $quarter),
            'increased_rme' => $this->increased($rme, TaxRegime::Rme, $increaseRule, $quarter),
        ];
        $sheets['summary'] = $this->summary($sheets);

        return [
            'period' => ['year' => $year, 'quarter' => $quarter, 'start' => $this->quarterStart->toDateString(), 'end' => $this->end->toDateString()],
            'increase_rule' => $increaseRule,
            'sheets' => $sheets,
            'receipts' => $this->receipts($members->pluck('id')->all()),
            'anomalies' => $this->anomalies($members, $tee),
            'sources' => $this->sources($members),
        ];
    }

    /**
     * Adhérents actifs TEE et RME du portefeuille CGA à une date (évolution du rapport modernisé),
     * selon les mêmes règles que les états : portefeuille CGA à la date, régime à la date.
     *
     * @return array{tee: int, rme: int}
     */
    public function activeByRegime(string $organizationId, CarbonImmutable $date): array
    {
        $counts = ['tee' => 0, 'rme' => 0];
        Member::query()->with('regimeHistory')->where('organization_id', $organizationId)->regulatoryAt($date)->get()
            ->each(function (Member $member) use ($date, &$counts): void {
                $regime = $this->regimeOn($member, $date);
                if ($regime === TaxRegime::Tee || $regime === TaxRegime::Rme) {
                    $counts[strtolower($regime->value)]++;
                }
            });

        return $counts;
    }

    /**
     * Régime du dossier à une date, d'après l'historique des régimes (F-ADH-05) ; sans historique, le
     * régime du dossier.
     */
    public function regimeOn(Member $member, CarbonImmutable $date): TaxRegime
    {
        $history = $member->regimeHistory->sortBy(static fn (MemberRegimeChange $change): string => $change->effective_date->toDateString().$change->id)->values();
        if ($history->isEmpty()) {
            return $member->regime;
        }
        $current = $history->first();
        foreach ($history as $change) {
            if ($change->effective_date->greaterThan($date)) {
                break;
            }
            $current = $change;
        }

        return $current->regime;
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(Member $member): array
    {
        $parcel = array_filter([$member->land_section, $member->land_parcel], static fn (?string $value): bool => filled($value));

        return [
            'member_id' => $member->id,
            'name' => $member->company_name,
            'ncc' => $member->ncc,
            'activity' => $member->main_activity,
            'location' => implode(', ', array_filter([$member->address, $member->commune?->name], static fn (?string $value): bool => filled($value))),
            // Numéro historique affiché tel quel (RG-RPT-03).
            'membership_number' => $member->legacy_number ?? $member->membership_number,
            'adhesion_date' => $member->adhesion_date?->toDateString(),
            'section_parcel' => $parcel === [] ? null : implode(' / ', $parcel),
            'reference_revenue' => $this->revenue($member, $this->year),
        ];
    }

    /**
     * Chiffre d'affaires de référence : le réalisé de l'année, à défaut le prévisionnel ; pour l'année du
     * rapport, la dernière année connue jusqu'à elle (RG-DATA-06 : une valeur Compta Flow remplace la
     * saisie à l'enregistrement).
     */
    private function revenue(Member $member, int $year, bool $exact = false): ?int
    {
        $rows = $member->revenues
            ->filter(static fn (MemberRevenue $revenue): bool => $exact ? $revenue->year === $year : $revenue->year <= $year)
            ->sortBy([['year', 'desc'], [static fn (MemberRevenue $revenue): int => $revenue->kind === RevenueKind::Actual ? 0 : 1, 'asc']]);

        return $rows->first()?->amount;
    }

    /**
     * Groupes par CDI (nom du CDI, puis « CDI non renseigné ») avec leurs totaux.
     *
     * @param  Collection<int, Member>  $members
     * @param  callable(Member): array<string, mixed>  $row
     * @param  list<string>  $sum  colonnes totalisées
     * @return list<array{tax_office: array{id: string, code: string, name: string}|null, rows: list<array<string, mixed>>, totals: array<string, int>}>
     */
    private function grouped(Collection $members, callable $row, array $sum): array
    {
        $rows = $members->map(static fn (Member $member): array => ['office' => $member->tax_office_id, 'row' => $row($member)]);

        return $this->groupRows($rows, $sum);
    }

    /**
     * @param  Collection<int, array{office: string|null, row: array<string, mixed>}>  $rows
     * @param  list<string>  $sum
     * @return list<array{tax_office: array{id: string, code: string, name: string}|null, rows: list<array<string, mixed>>, totals: array<string, int>}>
     */
    private function groupRows(Collection $rows, array $sum): array
    {
        return $rows->groupBy(static fn (array $entry): string => $entry['office'] ?? '')
            ->map(function (Collection $entries, string $office) use ($sum): array {
                $list = $entries->pluck('row')->values()->all();
                $totals = ['count' => count($list)];
                foreach ($sum as $column) {
                    $totals[$column] = (int) array_sum(array_map(static fn (array $row): int => (int) ($row[$column] ?? 0), $list));
                }

                return ['tax_office' => $this->offices[$office] ?? null, 'rows' => $list, 'totals' => $totals];
            })
            ->sortBy(static fn (array $group): string => $group['tax_office'] === null ? "\u{FFFF}" : mb_strtolower($group['tax_office']['name']))
            ->values()
            ->all();
    }

    /**
     * TEE exigible avant et après abattement, acquittée (montant, mois), reste à recouvrer et taux, sur
     * l'année jusqu'à la date d'arrêté et sur le trimestre : échéances de paiement TEE non annulées dont
     * la date limite tombe dans la période, paiements confirmés au plus tard à la date d'arrêté.
     *
     * @param  list<string>  $memberIds
     * @return array<string, array<string, int|float|null>>
     */
    private function teeAmounts(array $memberIds): array
    {
        $empty = static fn (): array => ['gross' => 0, 'net' => 0, 'paid' => 0, 'months' => 0];
        $amounts = array_fill_keys($memberIds, ['annual' => $empty(), 'quarter' => $empty()]);
        foreach ($this->dueInstances($memberIds, 'tee', $this->yearStart, $this->end) as $instance) {
            $paid = min((int) $instance['paid'], (int) ($instance['net'] ?? 0));
            $months = $instance['net'] !== null && $instance['paid'] >= $instance['net'] ? (int) ($instance['months'] ?? 0) : 0;
            $periods = $instance['due']->greaterThanOrEqualTo($this->quarterStart) ? ['annual', 'quarter'] : ['annual'];
            foreach ($periods as $period) {
                $amounts[$instance['member_id']][$period]['gross'] += (int) ($instance['gross'] ?? $instance['net'] ?? 0);
                $amounts[$instance['member_id']][$period]['net'] += (int) ($instance['net'] ?? 0);
                $amounts[$instance['member_id']][$period]['paid'] += $paid;
                $amounts[$instance['member_id']][$period]['months'] += $months;
            }
        }

        $result = [];
        foreach ($amounts as $memberId => $periods) {
            foreach ($periods as $period => $values) {
                $result[$memberId]["{$period}_gross"] = $values['gross'];
                $result[$memberId]["{$period}_net"] = $values['net'];
                $result[$memberId]["{$period}_paid"] = $values['paid'];
                $result[$memberId]["{$period}_months"] = $values['months'];
                $result[$memberId]["{$period}_balance"] = max(0, $values['net'] - $values['paid']);
                $result[$memberId]["{$period}_rate"] = $values['net'] > 0 ? round(100 * $values['paid'] / $values['net'], 1) : null;
            }
        }

        return $result;
    }

    /**
     * Échéances de paiement d'une nature, non annulées, dont la date limite tombe dans la période, avec
     * leurs paiements confirmés au plus tard à la date d'arrêté.
     *
     * @param  list<string>  $memberIds
     * @return list<array{member_id: string, nature: string|null, due: CarbonImmutable, gross: int|null, net: int|null, months: int|null, paid: int}>
     */
    private function dueInstances(array $memberIds, ?string $nature, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $paidBy = null): array
    {
        if ($memberIds === []) {
            return [];
        }
        $paidBy ??= $this->end;
        $instances = ObligationInstance::query()
            ->whereIn('member_id', $memberIds)
            ->where('kind', InstanceKind::Payment)
            ->where('status', '<>', InstanceStatus::Cancelled)
            ->when($nature !== null, static fn (Builder $query) => $query->where('tax_nature', $nature))
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'member_id', 'tax_nature', 'due_date', 'amount_gross', 'amount_due', 'months_covered']);
        $paid = ObligationPayment::query()
            ->whereIn('instance_id', $instances->pluck('id')->all())
            ->where('status', PaymentStatus::Confirmed)
            ->where('paid_on', '<=', $paidBy->toDateString())
            ->groupBy('instance_id')
            ->selectRaw('instance_id, sum(amount) as total')
            ->pluck('total', 'instance_id');

        return $instances->map(static fn (ObligationInstance $instance): array => [
            'member_id' => $instance->member_id,
            'nature' => $instance->tax_nature?->value,
            'due' => CarbonImmutable::parse($instance->due_date),
            'gross' => $instance->amount_gross,
            'net' => $instance->amount_due,
            'months' => $instance->months_covered,
            'paid' => (int) ($paid[$instance->id] ?? 0),
        ])->values()->all();
    }

    /**
     * Paiements confirmés d'une période, par dossier et par nature d'impôt.
     *
     * @param  list<string>  $memberIds
     * @return array<string, array<string, int>>
     */
    private function paymentsByNature(array $memberIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($memberIds === []) {
            return [];
        }
        $result = [];
        ObligationPayment::query()
            ->whereIn('member_id', $memberIds)
            ->where('status', PaymentStatus::Confirmed)
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('member_id', 'tax_nature')
            ->selectRaw('member_id, tax_nature, sum(amount) as total')
            ->get()
            ->each(static function (ObligationPayment $payment) use (&$result): void {
                $nature = $payment->getRawOriginal('tax_nature');
                $result[$payment->member_id][(string) $nature] = (int) $payment->getAttribute('total');
            });

        return $result;
    }

    /**
     * Colonnes de la feuille « Suivi RME » à partir des montants par nature (config reports.rme_columns).
     *
     * @param  array<string, int>  $byNature
     * @return array<string, int>
     */
    private function rmeColumns(array $byNature): array
    {
        $columns = [];
        foreach ((array) config('reports.rme_columns') as $column => $natures) {
            $columns[$column] = (int) array_sum(array_map(static fn (string $nature): int => $byNature[$nature] ?? 0, (array) $natures));
        }
        $columns['total'] = (int) array_sum($columns);

        return $columns;
    }

    /**
     * Surclassements du trimestre (TEE → RME, RME → RSI) : changements de régime prenant effet dans le
     * trimestre, pour un dossier du portefeuille CGA la veille du changement ; chiffre d'affaires et
     * cotisations des années N-3 à N-1, cotisation N (TEE → RME), motif.
     *
     * @return list<array{tax_office: array{id: string, code: string, name: string}|null, rows: list<array<string, mixed>>, totals: array<string, int>}>
     */
    private function upgrades(string $organizationId, TaxRegime $from, TaxRegime $to): array
    {
        $years = range($this->year - (int) config('reports.upgrade_history_years'), $this->year - 1);
        $nature = (string) (config('reports.regime_tax_nature')[$from->value] ?? '');
        $rows = collect();
        $changes = MemberRegimeChange::query()
            ->where('organization_id', $organizationId)
            ->where('regime', $to)
            ->whereBetween('effective_date', [$this->quarterStart->toDateString(), $this->end->toDateString()])
            ->with(['member.commune', 'member.revenues', 'member.regimeHistory'])
            ->orderBy('effective_date')
            ->get();
        foreach ($changes as $change) {
            $member = $change->member;
            $eve = CarbonImmutable::parse($change->effective_date)->subDay();
            if ($this->regimeOn($member, $eve) !== $from || ! Member::query()->whereKey($member->id)->regulatoryAt($eve)->exists()) {
                continue;
            }
            $row = [...$this->identity($member), 'effective_date' => CarbonImmutable::parse($change->effective_date)->toDateString(), 'reason' => $change->reason];
            foreach ($years as $offset => $year) {
                $row['revenue_n'.($offset - count($years))] = $this->revenue($member, $year, true);
                if ($from === TaxRegime::Tee) {
                    $row['dues_n'.($offset - count($years))] = $this->annualDue($member->id, $nature, $year, 'net');
                }
            }
            if ($from === TaxRegime::Tee) {
                $row['dues_n'] = $this->annualDue($member->id, $nature, $this->year, 'net');
            }
            $rows->push(['office' => $member->tax_office_id, 'row' => $row]);
        }

        return $this->groupRows($rows, []);
    }

    /**
     * Montant dû d'une nature sur une année (date limite dans l'année), après ou avant abattement.
     */
    private function annualDue(string $memberId, string $nature, int $year, string $amount, ?int $quarter = null): int
    {
        $from = $quarter === null ? CarbonImmutable::create($year, 1, 1) : CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1);
        $to = $quarter === null ? $from->endOfYear() : $from->addMonthsNoOverflow(2)->endOfMonth();

        return (int) ObligationInstance::query()
            ->where('member_id', $memberId)
            ->where('kind', InstanceKind::Payment)
            ->where('status', '<>', InstanceStatus::Cancelled)
            ->where('tax_nature', $nature)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->get(['amount_gross', 'amount_due'])
            ->sum(static fn (ObligationInstance $instance): int => (int) ($amount === 'gross' ? ($instance->amount_gross ?? $instance->amount_due) : $instance->amount_due));
    }

    /**
     * Résiliations du trimestre (radiations validées dont l'effet tombe dans le trimestre, hors
     * annulées), par régime du dossier à la veille de l'effet : chiffre d'affaires, reste à recouvrer de
     * l'année jusqu'à l'effet (TEE : exigible et acquitté ; RME : par nature), date et motif.
     *
     * @return array<string, list<array{tax_office: array{id: string, code: string, name: string}|null, rows: list<array<string, mixed>>, totals: array<string, int>}>>
     */
    private function terminations(string $organizationId, int $year, int $quarter): array
    {
        $rows = [TaxRegime::Tee->value => collect(), TaxRegime::Rme->value => collect()];
        $radiations = Radiation::query()
            ->where('organization_id', $organizationId)
            ->where('status', RadiationStatus::Validated)
            ->whereNull('cancelled_at')
            ->where('report_year', $year)
            ->where('report_quarter', $quarter)
            ->with(['member.commune', 'member.revenues', 'member.regimeHistory'])
            ->orderBy('effective_date')
            ->get();
        foreach ($radiations as $radiation) {
            $member = $radiation->member;
            $effect = CarbonImmutable::parse($radiation->effective_date);
            $regime = $this->regimeOn($member, $effect->subDay());
            if (! isset($rows[$regime->value])) {
                continue;
            }
            $row = [
                ...$this->identity($member),
                'effective_date' => $effect->toDateString(),
                'reason' => $radiation->reason->label(),
                'reason_other' => $radiation->reason_other,
            ];
            $due = $this->dueInstances([$member->id], null, CarbonImmutable::create($effect->year, 1, 1), $effect, $effect);
            if ($regime === TaxRegime::Tee) {
                $tee = array_filter($due, static fn (array $instance): bool => $instance['nature'] === 'tee');
                $row['due'] = (int) array_sum(array_map(static fn (array $instance): int => (int) ($instance['net'] ?? 0), $tee));
                $row['paid'] = (int) array_sum(array_map(static fn (array $instance): int => min($instance['paid'], (int) ($instance['net'] ?? 0)), $tee));
                $row['balance'] = max(0, $row['due'] - $row['paid']);
            } else {
                $balances = [];
                foreach ($due as $instance) {
                    $balances[(string) $instance['nature']] = ($balances[(string) $instance['nature']] ?? 0) + max(0, (int) ($instance['net'] ?? 0) - $instance['paid']);
                }
                $row = [...$row, ...$this->rmeColumns($balances)];
            }
            $rows[$regime->value]->push(['office' => $member->tax_office_id, 'row' => $row]);
        }

        return [
            TaxRegime::Tee->value => $this->groupRows($rows[TaxRegime::Tee->value], ['due', 'paid', 'balance']),
            TaxRegime::Rme->value => $this->groupRows($rows[TaxRegime::Rme->value], [...array_keys((array) config('reports.rme_columns')), 'total']),
        ];
    }

    /**
     * Adhérents dont la cotisation a augmenté selon la règle du réseau (RG-RPT-08) : base de comparaison
     * (montant annuel dû N / N-1, dû du trimestre / même trimestre N-1, chiffre d'affaires N / N-1), hausse
     * minimale en %, montant après ou avant abattement.
     *
     * @param  Collection<int, Member>  $members
     * @param  array{basis: string, min_increase_pct: int|float, amount: string}  $rule
     * @return list<array{tax_office: array{id: string, code: string, name: string}|null, rows: list<array<string, mixed>>, totals: array<string, int>}>
     */
    private function increased(Collection $members, TaxRegime $regime, array $rule, int $quarter): array
    {
        $nature = (string) (config('reports.regime_tax_nature')[$regime->value] ?? '');
        $rows = collect();
        foreach ($members as $member) {
            [$previous, $current] = match ($rule['basis']) {
                'revenue' => [$this->revenue($member, $this->year - 1, true), $this->revenue($member, $this->year, true)],
                'quarter_due' => [$this->annualDue($member->id, $nature, $this->year - 1, $rule['amount'], $quarter), $this->annualDue($member->id, $nature, $this->year, $rule['amount'], $quarter)],
                default => [$this->annualDue($member->id, $nature, $this->year - 1, $rule['amount']), $this->annualDue($member->id, $nature, $this->year, $rule['amount'])],
            };
            if (! IncreaseRule::isIncrease($previous, $current, (float) $rule['min_increase_pct'])) {
                continue;
            }
            $rows->push(['office' => $member->tax_office_id, 'row' => [
                ...$this->identity($member),
                'previous' => $previous,
                'current' => $current,
                'increase_pct' => IncreaseRule::percent((int) $previous, (int) $current),
            ]]);
        }

        return $this->groupRows($rows, ['previous', 'current']);
    }

    /**
     * Feuille « Résumé » : par CDI, adhérents TEE et RME actifs, nouveaux et résiliés, et le total du
     * centre. Agrégats seulement : c'est la seule donnée transmise au CNCGA (RG-RPT-04).
     *
     * @param  array<string, list<array{tax_office: array{id: string, code: string, name: string}|null, rows: list<array<string, mixed>>, totals: array<string, int>}>>  $sheets
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    private function summary(array $sheets): array
    {
        $columns = [
            'active_tee' => 'tee_follow_up', 'active_rme' => 'rme_follow_up', 'new_tee' => 'new_tee', 'new_rme' => 'new_rme',
            'terminated_tee' => 'terminations_tee', 'terminated_rme' => 'terminations_rme',
        ];
        $rows = [];
        foreach ($columns as $column => $sheet) {
            foreach ($sheets[$sheet] as $group) {
                $key = $group['tax_office']['id'] ?? '';
                $rows[$key] ??= ['tax_office' => $group['tax_office'], ...array_fill_keys(array_keys($columns), 0)];
                $rows[$key][$column] = $group['totals']['count'];
            }
        }
        $rows = collect($rows)
            ->map(static fn (array $row): array => [...$row, 'active_total' => $row['active_tee'] + $row['active_rme'], 'new_total' => $row['new_tee'] + $row['new_rme'], 'terminated_total' => $row['terminated_tee'] + $row['terminated_rme']])
            ->sortBy(static fn (array $row): string => $row['tax_office'] === null ? "\u{FFFF}" : mb_strtolower($row['tax_office']['name']))
            ->values()
            ->all();
        $totals = [];
        foreach ([...array_keys($columns), 'active_total', 'new_total', 'terminated_total'] as $column) {
            $totals[$column] = (int) array_sum(array_column($rows, $column));
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Recettes fiscales du trimestre du portefeuille, par nature d'impôt (paiements confirmés).
     *
     * @param  list<string>  $memberIds
     * @return array<string, int>
     */
    private function receipts(array $memberIds): array
    {
        $totals = [];
        foreach ($this->paymentsByNature($memberIds, $this->quarterStart, $this->end) as $natures) {
            foreach ($natures as $nature => $amount) {
                $totals[$nature] = ($totals[$nature] ?? 0) + $amount;
            }
        }
        ksort($totals);

        return [...$totals, 'total' => (int) array_sum($totals)];
    }

    /**
     * Anomalies signalées sur la section 5 (03-APPFLOW : liens vers les dossiers) : CDI absent, section et
     * parcelle absentes d'un TEE, chiffre d'affaires de référence absent, montant TEE du trimestre inconnu.
     *
     * @param  Collection<int, Member>  $members
     * @param  Collection<int, Member>  $tee
     * @return list<array{code: string, label: string, members: list<array{id: string, name: string}>}>
     */
    private function anomalies(Collection $members, Collection $tee): array
    {
        $unknownTee = ObligationInstance::query()
            ->whereIn('member_id', $tee->pluck('id')->all())
            ->where('kind', InstanceKind::Payment)
            ->where('tax_nature', 'tee')
            ->where('status', '<>', InstanceStatus::Cancelled)
            ->whereNull('amount_due')
            ->whereBetween('due_date', [$this->quarterStart->toDateString(), $this->end->toDateString()])
            ->distinct()
            ->pluck('member_id')
            ->all();
        $checks = [
            'missing_tax_office' => $members->filter(static fn (Member $member): bool => $member->tax_office_id === null),
            'missing_land_parcel' => $tee->filter(static fn (Member $member): bool => blank($member->land_section) || blank($member->land_parcel)),
            'missing_reference_revenue' => $members->filter(fn (Member $member): bool => $this->revenue($member, $this->year) === null),
            'unknown_tee_amount' => $tee->filter(static fn (Member $member): bool => in_array($member->id, $unknownTee, true)),
        ];
        $anomalies = [];
        foreach ($checks as $code => $found) {
            if ($found->isNotEmpty()) {
                $anomalies[] = [
                    'code' => $code,
                    'label' => trans_choice("report.anomalies.{$code}", $found->count(), ['count' => $found->count()]),
                    'members' => $found->map(static fn (Member $member): array => ['id' => $member->id, 'name' => $member->company_name])->values()->all(),
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Sources des chiffres d'affaires et des paiements du trimestre (RG-RPT-12, indicateur de source) :
     * nombre de valeurs par source.
     *
     * @param  Collection<int, Member>  $members
     * @return array{revenues: array<string, int>, payments: array<string, int>}
     */
    private function sources(Collection $members): array
    {
        $revenues = [];
        foreach ($members as $member) {
            foreach ($member->revenues as $revenue) {
                $revenues[$revenue->source->value] = ($revenues[$revenue->source->value] ?? 0) + 1;
            }
        }
        $payments = $members->isEmpty() ? [] : ObligationPayment::query()
            ->whereIn('member_id', $members->pluck('id')->all())
            ->where('status', PaymentStatus::Confirmed)
            ->whereBetween('paid_on', [$this->quarterStart->toDateString(), $this->end->toDateString()])
            ->groupBy('source')
            ->selectRaw('source, count(*) as total')
            ->pluck('total', 'source')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
        ksort($revenues);
        ksort($payments);

        return ['revenues' => $revenues, 'payments' => $payments];
    }
}
