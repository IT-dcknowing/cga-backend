<?php

use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Events\RadiationCancelled;
use App\Domain\Members\Events\RadiationValidated;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Radiation;
use App\Domain\Obligations\Actions\RecordObligationPayment;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Events\PaymentConfirmed;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\NationalReference;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Statistics\Actions\RefreshNetworkStats;
use App\Domain\Statistics\Actions\RefreshOrganizationStats;
use App\Domain\Statistics\Jobs\RefreshOrganizationStatsJob;
use App\Domain\Statistics\Models\StatNetworkMonthly;
use App\Domain\Statistics\Models\StatOrgDaily;
use App\Domain\Statistics\Support\MacroIndicators;
use App\Domain\Statistics\Support\NetworkAverages;
use App\Domain\Statistics\Support\OrgDailyCalculator;
use App\Domain\Statistics\Support\OrgStatsSummary;
use App\Domain\Statistics\Support\StatsCoverage;
use App\Domain\Statistics\Support\StatsRefresh;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Agrégats statistiques (06-IMPLEMENTATION-PLAN étape 8.1, PRD M15, 02-TRD §9) : stat_org_daily (portefeuille CGA
 * seulement, RG-STA-05), stat_network_monthly (tous les CGA, RG-STA-02), stats:refresh et recalcul ciblé
 * dédoublonné, couverture (RG-STA-01), moyennes à partir de 5 CGA (RG-STA-04), indicateurs macro (RG-STA-03).
 * Le jeu de référence du 1er trimestre 2026 est décrit dans statDataset ; les totaux attendus sont calculés à la
 * main dans chaque test.
 */

/**
 * @param  array<string, mixed>  $attributes
 */
function statMember(Organization $cga, array $attributes): Member
{
    return Member::factory()->active()->create(['organization_id' => $cga->id, ...$attributes]);
}

function statRevenue(Member $member, int $year, int $amount, string $kind): void
{
    DB::table('member_revenues')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $member->organization_id, 'member_id' => $member->id,
        'year' => $year, 'amount' => $amount, 'kind' => $kind, 'source' => 'entry', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function statRegime(Member $member, string $regime, string $effective): void
{
    DB::table('member_regime_history')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $member->organization_id, 'member_id' => $member->id,
        'regime' => $regime, 'effective_date' => $effective, 'reason' => 'Jeu de référence', 'created_at' => now(),
    ]);
}

function statRadiation(Member $member, string $effective, ?string $cancelledAt = null): void
{
    $user = User::factory()->create();
    DB::table('radiations')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $member->organization_id, 'member_id' => $member->id,
        'initiator' => 'cga', 'reason' => 'cessation', 'status' => 'validated', 'effective_date' => $effective,
        'observation' => 'Jeu de référence', 'requested_by' => $user->id, 'requested_at' => now(), 'validated_by' => $user->id,
        'validated_at' => now(), 'outcome' => 'closed', 'report_year' => (int) substr($effective, 0, 4),
        'report_quarter' => intdiv((int) substr($effective, 5, 2) - 1, 3) + 1, 'cancelled_at' => $cancelledAt,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function statCharge(Member $member, ChargeKind $kind, int $amount, string $due): MemberCharge
{
    $period = $kind === ChargeKind::Fees ? ['period_start' => substr($due, 0, 8).'01', 'period_end' => CarbonImmutable::parse($due)->endOfMonth()->toDateString()] : [];

    return MemberCharge::factory()->create(['member_id' => $member->id, 'organization_id' => $member->organization_id, 'kind' => $kind, 'amount' => $amount, 'due_date' => $due, ...$period]);
}

/**
 * Paiement confirmé (ou non) affecté aux sommes dues [somme, montant].
 *
 * @param  list<array{0: MemberCharge, 1: int}>  $allocations
 */
function statPayment(Member $member, string $paidOn, array $allocations, string $status = 'confirmed'): MemberPayment
{
    $total = (int) array_sum(array_column($allocations, 1));
    $payment = MemberPayment::factory()->create(['member_id' => $member->id, 'organization_id' => $member->organization_id, 'amount' => $total, 'paid_on' => $paidOn, 'status' => $status]);
    foreach ($allocations as [$charge, $amount]) {
        DB::table('member_payment_allocations')->insert(['payment_id' => $payment->id, 'charge_id' => $charge->id, 'amount' => $amount]);
    }

    return $payment;
}

function statTaxPayment(Member $member, string $nature, int $amount, string $paidOn, string $status = 'confirmed'): void
{
    $obligation = MemberObligation::factory()->create(['member_id' => $member->id]);
    $instance = ObligationInstance::factory()->create([
        'member_obligation_id' => $obligation->id, 'kind' => InstanceKind::Payment, 'tax_nature' => $nature,
        'period_label' => $paidOn, 'period_start' => $paidOn, 'period_end' => $paidOn, 'due_date' => $paidOn,
        'amount_due' => $amount, 'status' => InstanceStatus::ToPay,
    ]);
    DB::table('obligation_payments')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $member->organization_id, 'instance_id' => $instance->id,
        'member_id' => $member->id, 'tax_nature' => $nature, 'amount' => $amount, 'paid_on' => $paidOn,
        'source' => 'cga_entry', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function statConcern(Member $member, string $createdAt, array $attributes = []): Concern
{
    return Concern::factory()->create(['organization_id' => $member->organization_id, 'member_id' => $member->id, 'created_at' => $createdAt, ...$attributes]);
}

/**
 * Jeu de référence du 1er trimestre 2026, arrêté au 31/03 :
 * - M1 · TEE depuis 2025, secteur S1, commune C1 ; CA 2026 réalisé 12 M (prévisionnel 10 M écarté) ; honoraires de
 *   15 000 échus le 10/01, payés le 12/01 avec 5 000 de droit d'adhésion ; préoccupation ouverte le 02/03 à 9 h,
 *   première réponse le 03/03 à 9 h (24 h), traitée le 05/03 ;
 * - M2 · RME, entré le 10/02, secteur S1, commune C1 ; CA prévisionnel 5 M ; patente de 100 000 payée le 20/02
 *   (TVA de 50 000 non confirmée écartée) ;
 * - M3 · TEE reclassé RSI le 01/03, sans secteur ni commune ;
 * - M5 · RME depuis 2025, radié avec effet au 15/03 (une autre radiation annulée est écartée) ;
 * - M4 · portefeuille Cabinet, avec honoraires, paiement, impôt et préoccupation : écarté partout (RG-STA-05).
 *
 * @return array<string, Member|Sector|Commune>
 */
function statDataset(Organization $cga): array
{
    $sector = Sector::factory()->create();
    $commune = Commune::factory()->create();

    $m1 = statMember($cga, ['regime' => TaxRegime::Tee, 'adhesion_date' => '2025-05-10', 'cga_contract_signed_on' => '2025-05-10', 'sector_id' => $sector->id, 'commune_id' => $commune->id]);
    statRevenue($m1, 2026, 10_000_000, 'forecast');
    statRevenue($m1, 2026, 12_000_000, 'actual');
    statRevenue($m1, 2025, 9_000_000, 'actual');
    $fees = statCharge($m1, ChargeKind::Fees, 15_000, '2026-01-10');
    $entry = statCharge($m1, ChargeKind::EntryFee, 25_000, '2026-01-05');
    statPayment($m1, '2026-01-12', [[$fees, 15_000], [$entry, 5_000]]);
    statConcern($m1, '2026-03-02 09:00:00', ['first_response_at' => '2026-03-03 09:00:00', 'resolved_at' => '2026-03-05 10:00:00', 'status' => ConcernStatus::Resolved]);

    $m2 = statMember($cga, ['regime' => TaxRegime::Rme, 'adhesion_date' => '2026-02-10', 'cga_contract_signed_on' => '2026-02-10', 'sector_id' => $sector->id, 'commune_id' => $commune->id]);
    statRevenue($m2, 2026, 5_000_000, 'forecast');
    statTaxPayment($m2, 'patente', 100_000, '2026-02-20');
    statTaxPayment($m2, 'tva', 50_000, '2026-02-21', 'pending_confirmation');

    $m3 = statMember($cga, ['regime' => TaxRegime::Rsi, 'adhesion_date' => '2024-01-01', 'cga_contract_signed_on' => '2024-01-01', 'sector_id' => null, 'commune_id' => null]);
    statRegime($m3, 'TEE', '2024-01-01');
    statRegime($m3, 'RSI', '2026-03-01');

    $m5 = statMember($cga, ['regime' => TaxRegime::Rme, 'status' => MemberStatus::Radiated, 'adhesion_date' => '2025-01-01', 'cga_contract_signed_on' => '2025-01-01', 'cga_contract_ended_on' => '2026-03-15']);
    statRadiation($m5, '2026-03-15');
    statRadiation($m5, '2026-02-01', '2026-01-20 10:00:00');

    $m4 = Member::factory()->active()->cabinet()->create(['organization_id' => $cga->id, 'regime' => TaxRegime::Tee, 'adhesion_date' => '2025-01-01', 'cga_contract_signed_on' => null, 'sector_id' => $sector->id]);
    statRevenue($m4, 2026, 99_000_000, 'actual');
    $cabinetFees = statCharge($m4, ChargeKind::Fees, 40_000, '2026-01-10');
    statPayment($m4, '2026-01-12', [[$cabinetFees, 40_000]]);
    statTaxPayment($m4, 'patente', 70_000, '2026-02-20');
    statConcern($m4, '2026-03-02 10:00:00', ['first_response_at' => '2026-03-02 12:00:00']);

    return ['m1' => $m1, 'm2' => $m2, 'm3' => $m3, 'm4' => $m4, 'm5' => $m5, 'sector' => $sector, 'commune' => $commune];
}

/**
 * @return Collection<string, StatOrgDaily>
 */
function statRows(Organization $cga): Illuminate\Support\Collection
{
    return Tenancy::bypass(static fn () => StatOrgDaily::query()->where('organization_id', $cga->id)->orderBy('day')->get()->keyBy(static fn (StatOrgDaily $row): string => $row->day->toDateString()), 'Test : lecture des agrégats.');
}

/**
 * Ligne d'agrégat écrite directement (moyennes, couverture, indicateurs macro).
 *
 * @param  array<string, mixed>  $values
 */
function statRow(Organization $cga, string $day, array $values = []): void
{
    DB::table('stat_org_daily')->insert([
        'organization_id' => $cga->id, 'day' => $day, 'active_members' => 0, 'members_rme' => 0, 'members_tee' => 0, 'members_rsi' => 0,
        'new_members' => 0, 'radiations' => 0, 'fees_due' => 0, 'fees_paid' => 0, 'tax_paid_total' => 0, 'tax_paid_by_nature' => '{}',
        'declared_revenue' => 0, 'members_by_sector' => '{}', 'members_by_commune' => '{}', 'concerns_opened' => 0, 'concerns_closed' => 0,
        'concerns_responded' => 0, 'concerns_avg_response_hours' => null, 'computed_at' => now(), ...$values,
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 18:00:00'));
    $this->cga = Organization::factory()->cga()->create();
});

describe('agrégats journaliers d\'un CGA (stat_org_daily)', function () {
    it('RG-STA-05 : compte les stocks du jour sur le portefeuille CGA seul, par régime, secteur et commune, avec le CA de référence', function () {
        $data = statDataset($this->cga);

        expect(app(RefreshOrganizationStats::class)->execute($this->cga, 2026, CarbonImmutable::now()))->toBe(90);
        $rows = statRows($this->cga);

        expect($rows)->toHaveCount(90)
            ->and($rows['2026-01-01']->only(['active_members', 'members_tee', 'members_rme', 'members_rsi', 'declared_revenue']))
            ->toBe(['active_members' => 3, 'members_tee' => 2, 'members_rme' => 1, 'members_rsi' => 0, 'declared_revenue' => 12_000_000])
            ->and($rows['2026-02-10']->only(['active_members', 'members_rme', 'new_members', 'declared_revenue']))
            ->toBe(['active_members' => 4, 'members_rme' => 2, 'new_members' => 1, 'declared_revenue' => 17_000_000])
            ->and($rows['2026-03-01']->only(['members_tee', 'members_rsi']))->toBe(['members_tee' => 1, 'members_rsi' => 1])
            ->and($rows['2026-03-14']->active_members)->toBe(4)
            ->and($rows['2026-03-15']->only(['active_members', 'members_rme', 'radiations']))->toBe(['active_members' => 3, 'members_rme' => 1, 'radiations' => 1])
            ->and($rows['2026-02-01']->radiations)->toBe(0)
            ->and($rows['2026-03-31']->members_by_sector)->toEqual([$data['sector']->id => 2, 'none' => 1])
            ->and($rows['2026-03-31']->members_by_commune)->toEqual([$data['commune']->id => 2, 'none' => 1])
            ->and($rows['2026-01-01']->members_by_commune)->toEqual([$data['commune']->id => 1, 'none' => 2]);
    });

    it('RG-STA-05 : compte les flux du jour — honoraires échus et encaissés, impôts payés confirmés par nature, préoccupations', function () {
        statDataset($this->cga);
        app(RefreshOrganizationStats::class)->execute($this->cga, 2026, CarbonImmutable::now());
        $rows = statRows($this->cga);

        expect($rows['2026-01-10']->fees_due)->toBe(15_000)
            ->and($rows['2026-01-05']->fees_due)->toBe(0)
            ->and($rows['2026-01-12']->fees_paid)->toBe(15_000)
            ->and($rows['2026-02-20']->tax_paid_total)->toBe(100_000)
            ->and($rows['2026-02-20']->tax_paid_by_nature['patente'])->toBe(100_000)
            ->and($rows['2026-02-20']->tax_paid_by_nature['tva'])->toBe(0)
            ->and($rows['2026-02-21']->tax_paid_total)->toBe(0)
            ->and($rows['2026-03-02']->only(['concerns_opened', 'concerns_responded']))->toBe(['concerns_opened' => 1, 'concerns_responded' => 0])
            ->and($rows['2026-03-03']->concerns_responded)->toBe(1)
            ->and($rows['2026-03-03']->concerns_avg_response_hours)->toBe('24.00')
            ->and($rows['2026-03-05']->concerns_closed)->toBe(1)
            ->and($rows->sum('fees_paid'))->toBe(15_000)
            ->and($rows->sum('tax_paid_total'))->toBe(100_000)
            ->and($rows->sum('concerns_opened'))->toBe(1)
            ->and($rows->max('declared_revenue'))->toBe(17_000_000);
    });

    it('recalcule une année passée en entier, rien pour une année à venir, et remplace les lignes existantes', function () {
        statDataset($this->cga);
        $refresh = app(RefreshOrganizationStats::class);

        expect($refresh->execute($this->cga, 2025, CarbonImmutable::now()))->toBe(365)
            ->and($refresh->execute($this->cga, 2027, CarbonImmutable::now()))->toBe(0);
        $refresh->execute($this->cga, 2026, CarbonImmutable::now());
        $refresh->execute($this->cga, 2026, CarbonImmutable::now());
        $rows = statRows($this->cga);

        expect($rows)->toHaveCount(365 + 90)
            ->and($rows['2025-12-31']->declared_revenue)->toBe(9_000_000)
            ->and($rows['2025-01-01']->active_members)->toBe(2)
            ->and($rows['2025-05-10']->new_members)->toBe(1);
        expect(fn () => app(OrgDailyCalculator::class)->compute($this->cga->id, CarbonImmutable::parse('2025-12-01'), CarbonImmutable::parse('2026-01-31'), now()))
            ->toThrow(InvalidArgumentException::class);
    });

    it('cloisonne les agrégats : un CGA ne lit que ses lignes', function () {
        $other = Organization::factory()->cga()->create();
        statRow($this->cga, '2026-03-01', ['active_members' => 1, 'members_tee' => 1]);
        statRow($other, '2026-03-01', ['active_members' => 2, 'members_tee' => 2]);
        $manager = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $this->cga->id]);

        $visible = app(TenantContext::class)->runAs($manager, fn () => StatOrgDaily::query()->pluck('organization_id')->all());

        expect($visible)->toBe([$this->cga->id]);
    });
});

describe('vue mensuelle du réseau (stat_network_monthly) et stats:refresh', function () {
    it('RG-STA-02 : calcule les agrégats économiques des seuls CGA abonnés ; les cotisations et rapports portent sur tous les CGA', function () {
        $unsubscribed = Organization::factory()->cga()->create(['subscription_status' => SubscriptionStatus::None, 'subscription_started_at' => null]);
        $trial = Organization::factory()->cga()->create(['subscription_status' => SubscriptionStatus::Trial, 'subscription_started_at' => '2026-02-15']);
        statMember($unsubscribed, ['adhesion_date' => '2025-01-01', 'cga_contract_signed_on' => '2025-01-01']);
        statDataset($this->cga);
        $due = NetworkDue::factory()->create(['organization_id' => $unsubscribed->id, 'year' => 2026, 'amount_due' => 750_000]);
        NetworkDue::factory()->create(['organization_id' => $trial->id, 'year' => 2026, 'amount_due' => 500_000, 'status' => 'paid']);
        DB::table('network_due_payments')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $unsubscribed->id, 'network_due_id' => $due->id, 'amount' => 300_000,
            'paid_on' => '2026-02-15', 'method' => 'transfer', 'source' => 'cncga_entry', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        QuarterlyReport::factory()->create(['organization_id' => $unsubscribed->id, 'year' => 2026, 'quarter' => 1, 'deadline' => '2026-03-31', 'legal_deadline' => '2026-04-15', 'status' => 'transmitted', 'transmitted_at' => '2026-03-20 10:00:00', 'transmission_mode' => 'email']);

        $this->artisan('stats:refresh')->assertSuccessful()->expectsOutputToContain('2 CGA recalculé(s), 180 jour(s).');

        expect(Tenancy::bypass(static fn () => StatOrgDaily::query()->where('organization_id', $unsubscribed->id)->count(), 'Test.'))->toBe(0)
            ->and(statRows($trial))->toHaveCount(90);
        $months = StatNetworkMonthly::query()->orderBy('month')->get()->keyBy(static fn (StatNetworkMonthly $month): string => $month->month->toDateString());
        // La vue va du premier mois des cotisations et rapports au mois en cours de la base.
        expect($months->keys()->first())->toBe('2026-01-01')
            ->and($months->keys()->last())->toBe(CarbonImmutable::parse((string) DB::scalar('select current_date'))->startOfMonth()->toDateString())
            ->and($months['2026-02-01']->only(['dues_expected', 'dues_collected', 'dues_collected_year_to_date', 'dues_paid_cgas']))
            ->toBe(['dues_expected' => 1_250_000, 'dues_collected' => 300_000, 'dues_collected_year_to_date' => 300_000, 'dues_paid_cgas' => 1])
            ->and($months['2026-03-01']->only(['dues_collected', 'dues_collected_year_to_date', 'reports_due', 'reports_transmitted', 'reports_transmitted_late']))
            ->toBe(['dues_collected' => 0, 'dues_collected_year_to_date' => 300_000, 'reports_due' => 1, 'reports_transmitted' => 1, 'reports_transmitted_late' => 0])
            ->and($months['2026-01-01']->subscribed_cgas)->toBe($months['2026-03-01']->subscribed_cgas - 1)
            ->and($months['2026-03-01']->total_cgas)->toBe(StatsCoverage::registeredCgas()->count());
    });

    it('recalcule une année passée ou un seul CGA ; refuse une année invalide ; planifiée chaque nuit', function () {
        $this->artisan('stats:refresh', ['--year' => '2025', '--organization' => $this->cga->id])->assertSuccessful()->expectsOutputToContain('1 CGA recalculé(s), 365 jour(s).');
        $this->artisan('stats:refresh', ['--year' => 'deux'])->assertFailed();
        $this->artisan('stats:refresh', ['--year' => '2099'])->assertFailed();

        expect(collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'stats:refresh'))->expression)->toBe('0 2 * * *');
    });

    it('journalise puis relance une erreur du recalcul', function () {
        Log::spy();
        DB::statement('ALTER TABLE stat_org_daily RENAME TO stat_org_daily_unavailable');

        expect(fn () => $this->artisan('stats:refresh', ['--organization' => $this->cga->id])->run())->toThrow(QueryException::class);
        expect(fn () => (new RefreshOrganizationStatsJob($this->cga->id))->handle(app(RefreshNetworkStats::class)))->toThrow(QueryException::class);
        Log::shouldHaveReceived('error')->twice();
    });
});

describe('recalcul ciblé dédoublonné', function () {
    it('programme un seul recalcul par CGA après un paiement confirmé, une radiation validée ou annulée', function () {
        Queue::fake();
        $member = statMember($this->cga, []);
        $other = statMember(Organization::factory()->cga()->create(), []);
        $refresh = app(StatsRefresh::class);
        $payment = MemberPayment::factory()->create(['member_id' => $member->id, 'organization_id' => $this->cga->id]);
        $radiation = Tenancy::bypass(static function () use ($member): Radiation {
            statRadiation($member, '2026-03-15');

            return Radiation::query()->where('member_id', $member->id)->firstOrFail();
        }, 'Test.');

        $refresh->handle(new PaymentConfirmed($payment));
        $refresh->handle(new RadiationValidated($radiation));
        $refresh->handle(new RadiationCancelled($radiation, true));
        $refresh->queue($other->organization_id);

        Queue::assertPushed(RefreshOrganizationStatsJob::class, 2);
        Queue::assertPushed(RefreshOrganizationStatsJob::class, fn (RefreshOrganizationStatsJob $job): bool => $job->organizationId === $this->cga->id
            && $job->queue === config('queue-jobs.refresh_stats.queue') && $job->uniqueFor === 300 && $job->delay !== null);
    });

    it('écoute les événements qui modifient les agrégats', function () {
        Event::fake();

        foreach ([PaymentConfirmed::class, RadiationValidated::class, RadiationCancelled::class] as $event) {
            Event::assertListening($event, [StatsRefresh::class, 'handle']);
        }
    });

    it('programme le recalcul après un paiement d\'impôt enregistré par le CGA', function () {
        Queue::fake();
        $member = statMember($this->cga, []);
        $obligation = MemberObligation::factory()->create(['member_id' => $member->id]);
        $instance = ObligationInstance::factory()->create(['member_obligation_id' => $obligation->id, 'kind' => InstanceKind::Payment, 'tax_nature' => 'patente', 'due_date' => '2026-03-15', 'amount_due' => 50_000, 'status' => InstanceStatus::ToPay]);
        $manager = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $this->cga->id]);

        app(TenantContext::class)->runAs($manager, fn () => app(RecordObligationPayment::class)->execute($instance, 50_000, CarbonImmutable::parse('2026-03-20'), null, $manager->user));

        Queue::assertPushed(RefreshOrganizationStatsJob::class, fn (RefreshOrganizationStatsJob $job): bool => $job->organizationId === $this->cga->id);
    });

    it('le Job recalcule l\'année du CGA abonné, et rien pour un CGA non abonné', function () {
        statDataset($this->cga);
        $unsubscribed = Organization::factory()->cga()->create(['subscription_status' => SubscriptionStatus::None]);

        (new RefreshOrganizationStatsJob($this->cga->id))->handle(app(RefreshNetworkStats::class));
        (new RefreshOrganizationStatsJob($unsubscribed->id))->handle(app(RefreshNetworkStats::class));

        expect(statRows($this->cga))->toHaveCount(90)
            ->and(statRows($unsubscribed))->toHaveCount(0)
            ->and((new RefreshOrganizationStatsJob($this->cga->id))->uniqueId())->toBe($this->cga->id);
    });
});

describe('lecture des agrégats', function () {
    it('RG-STA-01 : donne la couverture « Sur la base de x CGA abonnés sur n » sur le registre, jamais un total écrit en dur', function () {
        $trial = Organization::factory()->cga()->create(['subscription_status' => SubscriptionStatus::Trial]);
        Organization::factory()->cga()->create(['subscription_status' => SubscriptionStatus::None]);
        Organization::factory()->cga()->create(['subscription_status' => SubscriptionStatus::Terminated, 'status' => OrganizationStatus::Terminated]);
        Organization::factory()->cncga()->create();
        statRow($trial, '2026-03-01');

        expect(app(StatsCoverage::class)->current())->toBe(['subscribed_cgas' => 2, 'total_cgas' => 3, 'label' => 'Sur la base de 2 CGA abonnés sur 3'])
            ->and(app(StatsCoverage::class)->forPeriod(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))
            ->toBe(['subscribed_cgas' => 1, 'total_cgas' => 3, 'label' => 'Sur la base de 1 CGA abonné sur 3']);
    });

    it('résume une période : flux sommés, stocks du dernier jour, délai de réponse pondéré par le nombre de réponses', function () {
        statRow($this->cga, '2026-03-01', ['active_members' => 10, 'members_tee' => 10, 'fees_paid' => 1_000, 'concerns_responded' => 2, 'concerns_avg_response_hours' => 10]);
        statRow($this->cga, '2026-03-02', ['active_members' => 12, 'members_tee' => 12, 'fees_paid' => 500, 'concerns_responded' => 1, 'concerns_avg_response_hours' => 40]);
        statRow($this->cga, '2026-04-01', ['active_members' => 99, 'members_tee' => 99, 'fees_paid' => 7]);

        $summary = Tenancy::bypass(fn () => app(OrgStatsSummary::class)->byOrganization(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'), [$this->cga->id]), 'Test.');

        expect($summary[$this->cga->id])->toMatchArray(['active_members' => 12, 'members_tee' => 12, 'fees_paid' => 1_500, 'concerns_responded' => 3, 'concerns_avg_response_hours' => 20.0]);
    });

    it('RG-STA-04 : ne donne une moyenne du réseau qu\'à partir de 5 CGA contributeurs (seuil paramétrable)', function () {
        $cgas = [$this->cga, ...Organization::factory()->cga()->count(3)->create()->all()];
        foreach ($cgas as $index => $cga) {
            statRow($cga, '2026-03-10', ['active_members' => 10 * ($index + 1), 'members_tee' => 10 * ($index + 1), 'fees_paid' => 1_000]);
        }
        $period = [CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')];

        expect(app(NetworkAverages::class)->compute(...$period, metrics: ['active_members', 'fees_paid']))
            ->toBe(['contributors' => 4, 'minimum' => 5, 'sufficient' => false, 'values' => ['active_members' => null, 'fees_paid' => null]]);

        statRow(Organization::factory()->cga()->create(), '2026-03-10', ['active_members' => 50, 'members_tee' => 50, 'fees_paid' => 6_000]);
        expect(app(NetworkAverages::class)->compute(...$period, metrics: ['active_members', 'fees_paid', 'concerns_avg_response_hours']))
            ->toBe(['contributors' => 5, 'minimum' => 5, 'sufficient' => true, 'values' => ['active_members' => 30.0, 'fees_paid' => 2_000.0, 'concerns_avg_response_hours' => null]]);

        app(SettingsService::class)->set('stats.min_cgas_for_average', 6);
        expect(app(NetworkAverages::class)->compute(...$period, metrics: ['active_members'])['values']['active_members'])->toBeNull();
    });

    it('RG-STA-03 : indicateurs macro « non disponible » tant que les références nationales de l\'année ne sont pas saisies', function () {
        statRow($this->cga, '2026-02-01', ['tax_paid_total' => 300_000, 'declared_revenue' => 40_000_000]);
        statRow($this->cga, '2026-12-31', ['tax_paid_total' => 200_000, 'declared_revenue' => 50_000_000]);

        expect(app(MacroIndicators::class)->forYear(2026))->toBe([
            'year' => 2026, 'tax_revenue' => 500_000, 'member_revenue' => 50_000_000,
            'budget_contribution' => ['available' => false, 'rate' => null, 'label' => 'Non disponible — saisir les références 2026'],
            'gdp_contribution' => ['available' => false, 'rate' => null, 'label' => 'Non disponible — saisir les références 2026'],
        ]);

        NationalReference::factory()->create(['year' => 2026, 'domestic_tax_revenue' => 1_000_000_000, 'nominal_gdp' => null]);
        $macro = app(MacroIndicators::class)->forYear(2026);
        expect($macro['budget_contribution'])->toBe(['available' => true, 'rate' => 0.0005, 'label' => null])
            ->and($macro['gdp_contribution']['available'])->toBeFalse();
    });
});
