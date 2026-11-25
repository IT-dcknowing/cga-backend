<?php

use App\Domain\Announcements\Models\Announcement;
use App\Domain\Auth\Support\AuthSession;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Statistics\Actions\RefreshNetworkStats;
use App\Domain\Statistics\Jobs\RefreshStatisticsJob;
use App\Domain\Statistics\Support\StatPeriod;
use App\Domain\Statistics\Support\Variation;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Tableaux de bord et statistiques (06-IMPLEMENTATION-PLAN étape 8.2, PRD M15) : tableaux de bord du CNCGA, du CGA
 * et de l'adhérent pour une période avec la variation N-1 (F-DSH-01 à 04), statistiques nationales, carte par
 * Direction régionale et détail par commune (F-STA-01, RG-STA-01 à 03, 05), position du CGA (F-STA-02, RG-STA-04),
 * bouton « Actualiser ». Décision 0016.
 */

function dashSpace(Membership $membership): TestCase
{
    return inSpace($membership)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()]);
}

/**
 * Ligne d'agrégat écrite directement.
 *
 * @param  array<string, mixed>  $values
 */
function dashRow(Organization $cga, string $day, array $values = []): void
{
    DB::table('stat_org_daily')->insert([
        'organization_id' => $cga->id, 'day' => $day, 'active_members' => 0, 'members_rme' => 0, 'members_tee' => 0, 'members_rsi' => 0,
        'new_members' => 0, 'radiations' => 0, 'fees_due' => 0, 'fees_paid' => 0, 'tax_paid_total' => 0, 'tax_paid_by_nature' => '{}',
        'declared_revenue' => 0, 'members_by_sector' => '{}', 'members_by_commune' => '{}', 'concerns_opened' => 0, 'concerns_closed' => 0,
        'concerns_responded' => 0, 'concerns_avg_response_hours' => null, 'computed_at' => '2026-03-31 02:00:00', ...$values,
    ]);
}

function dashDuePayment(NetworkDue $due, int $amount, string $paidOn): void
{
    DB::table('network_due_payments')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $due->organization_id, 'network_due_id' => $due->id, 'amount' => $amount,
        'paid_on' => $paidOn, 'method' => 'transfer', 'source' => 'cncga_entry', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function dashInstance(Member $member, string $nature, string $due, int $amount, InstanceKind $kind = InstanceKind::Payment): ObligationInstance
{
    $obligation = MemberObligation::factory()->create(['member_id' => $member->id]);

    return ObligationInstance::factory()->create([
        'member_obligation_id' => $obligation->id, 'kind' => $kind, 'tax_nature' => $nature, 'period_label' => $due, 'period_start' => $due,
        'period_end' => $due, 'due_date' => $due, 'amount_due' => $amount, 'status' => InstanceStatus::ToPay,
        'category' => match ($nature) {
            'cnps' => ObligationCategory::Cnps, 'cmu' => ObligationCategory::Cmu, default => ObligationCategory::Tax
        },
    ]);
}

function dashFees(Member $member, string $due, int $amount, ChargeStatus $status, int $paid = 0): MemberCharge
{
    return MemberCharge::factory()->create([
        'member_id' => $member->id, 'organization_id' => $member->organization_id, 'kind' => ChargeKind::Fees, 'amount' => $amount,
        'amount_paid' => $paid, 'due_date' => $due, 'status' => $status, 'period_start' => substr($due, 0, 8).'01',
        'period_end' => CarbonImmutable::parse($due)->endOfMonth()->toDateString(),
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 18:00:00'));
    $this->cncga = Organization::factory()->cncga()->create();
    $this->president = Membership::factory()->role(Role::CncgaPresident)->create(['organization_id' => $this->cncga->id]);
    $this->duesOfficer = Membership::factory()->role(Role::CncgaDues)->create(['organization_id' => $this->cncga->id]);
    $this->manager = Membership::factory()->role(Role::CgaManager)->create();
    $this->cga = $this->manager->organization;
    $this->secretary = Membership::factory()->role(Role::CgaSecretary)->create(['organization_id' => $this->cga->id]);
});

describe('période et variation (F-DSH-04)', function () {
    it('donne le jour, le mois, l\'année ou la période personnalisée, et la même période de N-1', function () {
        $date = CarbonImmutable::parse('2028-02-29');

        expect(StatPeriod::of(StatPeriod::DAY, $date)->previousYear()->toArray())->toMatchArray(['from' => '2027-02-28', 'to' => '2027-02-28'])
            ->and(StatPeriod::of(StatPeriod::MONTH, $date)->toArray())->toBe(['type' => 'month', 'from' => '2028-02-01', 'to' => '2028-02-29', 'previous' => ['from' => '2027-02-01', 'to' => '2027-02-28']])
            ->and(StatPeriod::of(StatPeriod::YEAR, $date)->toArray())->toMatchArray(['from' => '2028-01-01', 'to' => '2028-12-31', 'previous' => ['from' => '2027-01-01', 'to' => '2027-12-31']])
            ->and(StatPeriod::of(StatPeriod::CUSTOM, $date, CarbonImmutable::parse('2028-01-10'), CarbonImmutable::parse('2028-03-05'))->toArray())
            ->toMatchArray(['from' => '2028-01-10', 'to' => '2028-03-05', 'previous' => ['from' => '2027-01-10', 'to' => '2027-03-05']])
            ->and(StatPeriod::of('inconnue', $date)->type)->toBe(StatPeriod::MONTH)
            ->and(StatPeriod::of(StatPeriod::YEAR, $date)->until(CarbonImmutable::parse('2028-06-15'))->toDateString())->toBe('2028-06-15')
            ->and(Variation::of(12, 10))->toBe(['value' => 12, 'previous' => 10, 'change' => 2, 'change_pct' => 20.0])
            ->and(Variation::of(5, 0))->toBe(['value' => 5, 'previous' => 0, 'change' => 5, 'change_pct' => null])
            ->and(Variation::of(null, 3))->toBe(['value' => null, 'previous' => 3, 'change' => null, 'change_pct' => null]);
    });

    it('refuse une période inconnue, une date future, une période personnalisée incomplète ou trop longue (422)', function () {
        dashSpace($this->president)->getJson('/api/v1/network/dashboard?period=week')->assertUnprocessable()->assertJsonValidationErrors('period');
        dashSpace($this->president)->getJson('/api/v1/network/dashboard?date=2026-04-01')->assertUnprocessable()->assertJsonValidationErrors('date');
        dashSpace($this->president)->getJson('/api/v1/network/dashboard?period=custom&from=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        dashSpace($this->president)->getJson('/api/v1/network/dashboard?period=custom&from=2026-03-10&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        config(['cga.statistics.max_period_days' => 30]);
        dashSpace($this->president)->getJson('/api/v1/network/dashboard?period=custom&from=2026-01-01&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        dashSpace($this->president)->getJson('/api/v1/network/dashboard?period=custom&from=2026-02-01&to=2026-03-02')->assertOk()->assertJsonPath('data.period.from', '2026-02-01');
    });
});

describe('tableau de bord du CNCGA (F-DSH-01)', function () {
    beforeEach(function () {
        dashRow($this->cga, '2026-03-10', ['active_members' => 10, 'members_tee' => 10]);
        dashRow($this->cga, '2025-03-10', ['active_members' => 8, 'members_tee' => 8]);
        $due = NetworkDue::factory()->create(['organization_id' => $this->cga->id, 'year' => 2026, 'amount_due' => 750_000, 'status' => 'unpaid']);
        dashDuePayment($due, 200_000, '2026-03-05');
        dashDuePayment(NetworkDue::factory()->create(['organization_id' => $this->cga->id, 'year' => 2025, 'amount_due' => 700_000]), 100_000, '2025-03-08');
        Concern::factory()->toNetwork()->create(['organization_id' => $this->cga->id, 'created_at' => '2026-03-02 10:00:00']);
        Concern::factory()->toNetwork()->create(['organization_id' => $this->cga->id, 'created_at' => '2025-03-03 10:00:00', 'status' => ConcernStatus::Resolved, 'resolved_at' => '2025-03-10 10:00:00']);
        QuarterlyReport::factory()->create(['organization_id' => $this->cga->id, 'year' => 2025, 'quarter' => 4, 'deadline' => '2026-01-31', 'legal_deadline' => '2026-02-15', 'is_late' => true]);
        $this->cga->forceFill(['agreement_expires_at' => '2026-06-30'])->save();
        $training = Training::factory()->create(['organization_id' => $this->cncga->id, 'status' => TrainingStatus::Published, 'capacity' => 1, 'published_at' => now()]);
        DB::table('training_registrations')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $this->cga->id, 'training_id' => $training->id, 'membership_id' => $this->secretary->id,
            'user_id' => $this->secretary->user_id, 'registered_by' => $this->manager->user_id, 'status' => 'registered', 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    it('donne les 4 indicateurs avec la variation N-1, la couverture, le graphique des cotisations et les alertes', function () {
        DB::statement('REFRESH MATERIALIZED VIEW stat_network_monthly');

        $response = dashSpace($this->president)->getJson('/api/v1/network/dashboard')->assertOk();

        $response->assertJsonPath('data.period', ['type' => 'month', 'from' => '2026-03-01', 'to' => '2026-03-31', 'previous' => ['from' => '2025-03-01', 'to' => '2025-03-31']])
            ->assertJsonPath('data.coverage.subscribed_cgas', fn (int $count): bool => $count >= 1)
            ->assertJsonPath('data.computed_at', '2026-03-31T02:00:00+00:00')
            ->assertJsonPath('data.indicators.members', ['value' => 10, 'previous' => 8, 'change' => 2, 'change_pct' => 25])
            ->assertJsonPath('data.indicators.dues.collected', ['value' => 200_000, 'previous' => 100_000, 'change' => 100_000, 'change_pct' => 100])
            ->assertJsonPath('data.indicators.dues.expected', 750_000)
            ->assertJsonPath('data.indicators.dues.collected_year_to_date', 200_000)
            ->assertJsonPath('data.indicators.dues.rate', 0.267)
            ->assertJsonPath('data.indicators.concerns.open', 1)
            ->assertJsonPath('data.indicators.concerns.submitted', ['value' => 1, 'previous' => 1, 'change' => 0, 'change_pct' => 0])
            ->assertJsonPath('data.dues_chart.0.month', '2026-01-01')
            ->assertJsonPath('data.dues_chart.2', ['month' => '2026-03-01', 'collected' => 200_000, 'collected_year_to_date' => 200_000, 'expected' => 750_000]);
        $alerts = collect($response->json('data.alerts'))->keyBy('code');
        expect($alerts->keys()->all())->toBe(['reports_late', 'dues_unpaid', 'agreements_expiring', 'trainings_almost_full'])
            ->and($alerts['reports_late']['label'])->toBe('1 rapport en retard')
            ->and($alerts['dues_unpaid']['count'])->toBe(1)
            ->and($alerts['agreements_expiring']['items'])->toBe([['id' => $this->cga->id, 'name' => $this->cga->name, 'expires_at' => '2026-06-30']])
            ->and($alerts['trainings_almost_full']['items'][0]['seats_left'])->toBe(0);
    });

    it('ne rend que les blocs ouverts par les droits ; réservé aux agents du CNCGA (403)', function () {
        $response = dashSpace($this->duesOfficer)->getJson('/api/v1/network/dashboard?period=year')->assertOk();

        expect(array_keys($response->json('data.indicators')))->toBe(['cgas', 'dues', 'members'])
            ->and(collect($response->json('data.alerts'))->pluck('code')->all())->toBe(['dues_unpaid'])
            ->and($response->json('data.indicators.members.value'))->toBe(10);
        dashSpace($this->manager)->getJson('/api/v1/network/dashboard')->assertForbidden();
        $member = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => Member::factory()->active()->create(['organization_id' => $this->cga->id])->id]);
        dashSpace($member)->getJson('/api/v1/network/dashboard')->assertForbidden();
    });
});

describe('tableau de bord du CGA (F-DSH-02)', function () {
    beforeEach(function () {
        dashRow($this->cga, '2026-03-15', ['active_members' => 5, 'members_tee' => 5, 'new_members' => 2, 'fees_due' => 30_000, 'fees_paid' => 20_000]);
        dashRow($this->cga, '2025-03-15', ['active_members' => 4, 'members_tee' => 4, 'fees_due' => 30_000, 'fees_paid' => 10_000]);
        $other = Organization::factory()->cga()->create();
        dashRow($other, '2026-03-15', ['active_members' => 99, 'members_tee' => 99, 'fees_paid' => 99_000]);

        Member::factory()->create(['organization_id' => $this->cga->id]);
        Member::factory()->cabinet()->create(['organization_id' => $this->cga->id]);
        $rsi = Member::factory()->active()->create(['organization_id' => $this->cga->id, 'regime' => 'RSI', 'rsi_since' => '2025-12-01']);
        DB::table('member_pieces')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->cga->id, 'member_id' => $rsi->id, 'piece_type' => 'rccm', 'status' => 'expected', 'created_at' => now(), 'updated_at' => now()]);
        $late = Member::factory()->active()->create(['organization_id' => $this->cga->id]);
        dashFees($late, '2025-11-10', 15_000, ChargeStatus::Overdue);
        dashFees($late, '2026-03-05', 15_000, ChargeStatus::Overdue, 5_000);
        $recent = Member::factory()->active()->create(['organization_id' => $this->cga->id]);
        dashFees($recent, '2026-03-10', 15_000, ChargeStatus::Overdue);
        dashInstance($recent, 'patente', '2026-04-08', 40_000);
        DB::table('radiations')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $this->cga->id, 'member_id' => $recent->id, 'initiator' => 'cga', 'reason' => 'cessation',
            'status' => 'requested', 'effective_date' => '2026-04-30', 'observation' => 'Cessation', 'requested_by' => $this->manager->user_id,
            'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Concern::factory()->create(['organization_id' => $this->cga->id, 'member_id' => $recent->id, 'awaiting_reply_since' => '2026-03-01 08:00:00']);
        QuarterlyReport::factory()->create(['organization_id' => $this->cga->id, 'year' => 2026, 'quarter' => 1, 'deadline' => '2026-04-30', 'legal_deadline' => '2026-05-15', 'completeness' => ['identity' => 100, 'people' => 50]]);
    });

    it('donne les indicateurs de son seul centre (portefeuille CGA) avec la variation N-1, et les alertes cliquables', function () {
        $response = dashSpace($this->manager)->getJson('/api/v1/cga/dashboard')->assertOk();

        $response->assertJsonPath('data.indicators.members.active', ['value' => 5, 'previous' => 4, 'change' => 1, 'change_pct' => 25])
            ->assertJsonPath('data.indicators.members.new.value', 2)
            ->assertJsonPath('data.indicators.members.to_complete', 1)
            ->assertJsonPath('data.indicators.fees.paid', ['value' => 20_000, 'previous' => 10_000, 'change' => 10_000, 'change_pct' => 100])
            ->assertJsonPath('data.indicators.fees.due.change', 0)
            ->assertJsonPath('data.indicators.fees.unpaid', 25_000)
            ->assertJsonPath('data.indicators.upcoming', ['days' => 15, 'count' => 1, 'members' => 1])
            ->assertJsonPath('data.indicators.report.quarter', 1)
            ->assertJsonPath('data.indicators.report.completeness', 75);
        $alerts = collect($response->json('data.alerts'))->keyBy('code');
        expect($alerts->keys()->all())->toBe(['rsi_active', 'members_to_complete', 'pieces_pending', 'fees_overdue', 'radiations_pending', 'invoices_pending', 'closing_late', 'concerns_unanswered'])
            ->and(Arr::only($alerts['rsi_active'], ['count', 'overdue']))->toBe(['count' => 1, 'overdue' => 1])
            ->and(Arr::only($alerts['fees_overdue'], ['count', 'overdue']))->toBe(['count' => 2, 'overdue' => 1])
            ->and($alerts['fees_overdue']['label'])->toBe('2 adhérents avec des honoraires impayés')
            ->and($alerts['pieces_pending']['count'])->toBe(1)
            ->and($alerts['radiations_pending']['count'])->toBe(1)
            ->and($alerts['invoices_pending']['count'])->toBe(0)
            ->and($alerts['closing_late']['count'])->toBe(0)
            ->and($alerts['concerns_unanswered']['count'])->toBe(1);
    });

    it('ne rend que les blocs ouverts par les droits ; réservé aux agents d\'un CGA (403)', function () {
        $response = dashSpace($this->secretary)->getJson('/api/v1/cga/dashboard?period=day&date=2026-03-15')->assertOk();

        expect(array_keys($response->json('data.indicators')))->toBe(['members', 'report'])
            ->and($response->json('data.indicators.members.active.value'))->toBe(5)
            ->and(collect($response->json('data.alerts'))->pluck('code')->all())->toBe(['rsi_active', 'members_to_complete', 'pieces_pending', 'closing_late', 'concerns_unanswered']);
        dashSpace($this->president)->getJson('/api/v1/cga/dashboard')->assertForbidden();
    });
});

describe('tableau de bord de l\'adhérent (F-DSH-03)', function () {
    beforeEach(function () {
        $this->record = Member::factory()->active()->create(['organization_id' => $this->cga->id]);
        $this->owner = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => $this->record->id]);
        dashInstance($this->record, 'patente', '2026-04-10', 50_000);
        dashInstance($this->record, 'cnps', '2026-03-31', 20_000);
        dashInstance($this->record, 'cmu', '2026-03-20', 5_000);
        dashFees($this->record, '2026-02-10', 15_000, ChargeStatus::Overdue, 3_000);
        dashFees($this->record, '2026-04-10', 15_000, ChargeStatus::Due);
        GeneratedDocument::factory()->create(['member_id' => $this->record->id, 'title' => 'Attestation 2026', 'visible_to_member' => true]);
        GeneratedDocument::factory()->create(['member_id' => $this->record->id, 'title' => 'Document interne', 'visible_to_member' => false]);
        Tenancy::bypass(fn () => Announcement::factory()->create(['organization_id' => $this->cga->id, 'title' => 'Fermeture vendredi', 'created_by' => $this->manager->user_id]), 'Test.');
    });

    it('donne la prochaine échéance, les impôts et CNPS + CMU dus, les échéances du mois, les honoraires, le dernier message et les documents', function () {
        dashSpace($this->owner)->getJson('/api/v1/member/dashboard')->assertOk()
            ->assertJsonPath('data.next_due.due_date', '2026-03-31')
            ->assertJsonPath('data.next_due.amount', 20_000)
            ->assertJsonPath('data.taxes_due', 50_000)
            ->assertJsonPath('data.social_due', 25_000)
            ->assertJsonPath('data.month_dues.count', 2)
            ->assertJsonPath('data.fees', ['status' => 'overdue', 'overdue_amount' => 12_000, 'overdue_periods' => 1, 'next' => ['due_date' => '2026-04-10', 'amount' => 15_000]])
            ->assertJsonPath('data.last_message.title', 'Fermeture vendredi')
            ->assertJsonPath('data.last_message.read', false)
            ->assertJsonCount(1, 'data.recent_documents')
            ->assertJsonPath('data.recent_documents.0.title', 'Attestation 2026');
    });

    it('ne garde que les documents d\'un accès en consultation seule ; réservé à l\'adhérent (403)', function () {
        $this->owner->forceFill(['status' => MembershipStatus::ReadOnly, 'read_only_until' => '2026-09-30'])->save();
        $this->record->forceFill(['status' => MemberStatus::Radiated])->save();

        $data = dashSpace($this->owner)->getJson('/api/v1/member/dashboard')->assertOk()->json('data');

        expect(array_keys($data))->toBe(['recent_documents']);
        dashSpace($this->manager)->getJson('/api/v1/member/dashboard')->assertForbidden();
        dashSpace($this->president)->getJson('/api/v1/member/dashboard')->assertForbidden();
    });
});

describe('statistiques nationales et carte (F-STA-01)', function () {
    beforeEach(function () {
        $this->directorate = RegionalDirectorate::factory()->create(['name' => 'DR Abidjan Nord']);
        $this->commune = Commune::factory()->create(['regional_directorate_id' => $this->directorate->id, 'name' => 'Cocody']);
        $this->sector = Sector::factory()->create(['name' => 'Commerce']);
        $this->cga->forceFill(['regional_directorate_id' => $this->directorate->id])->save();
        $this->second = Organization::factory()->cga()->create(['name' => 'CGA SANS DR']);
        dashRow($this->cga, '2026-03-20', [
            'active_members' => 3, 'members_tee' => 2, 'members_rme' => 1, 'tax_paid_total' => 150_000, 'tax_paid_by_nature' => '{"patente": 100000, "tva": 50000}',
            'declared_revenue' => 9_000_000, 'members_by_sector' => json_encode([$this->sector->id => 2, 'none' => 1]), 'members_by_commune' => json_encode([$this->commune->id => 3]),
        ]);
        dashRow($this->cga, '2026-03-01', ['active_members' => 1, 'members_tee' => 1, 'members_by_sector' => json_encode([$this->sector->id => 1])]);
        dashRow($this->second, '2026-03-21', ['active_members' => 2, 'members_rsi' => 2, 'tax_paid_total' => 40_000, 'tax_paid_by_nature' => '{"patente": 40000}', 'declared_revenue' => 1_000_000, 'members_by_sector' => json_encode([$this->sector->id => 2])]);
    });

    it('RG-STA-01, 03, 05 : couverture, indicateurs macro, recettes par nature et par CGA, adhérents par régime, secteur et DR', function () {
        $data = dashSpace($this->president)->getJson('/api/v1/network/statistics?period=month')->assertOk()->json('data');

        expect($data['coverage']['subscribed_cgas'])->toBe(2)
            ->and($data['coverage']['label'])->toContain('Sur la base de 2 CGA abonnés sur')
            ->and($data['macro']['budget_contribution'])->toBe(['available' => false, 'rate' => null, 'label' => 'Non disponible — saisir les références 2026'])
            ->and($data['tax_revenue'])->toBe(['total' => 190_000, 'by_nature' => ['patente' => 140_000, 'tva' => 50_000]])
            ->and(array_column($data['by_cga'], 'tax_paid_total'))->toBe([150_000, 40_000])
            ->and($data['by_cga'][0]['name'])->toBe($this->cga->name)
            ->and($data['by_regime'])->toBe(['RME' => 1, 'TEE' => 2, 'RSI' => 2])
            ->and($data['by_sector'])->toBe([['id' => $this->sector->id, 'name' => 'Commerce', 'members' => 4], ['id' => null, 'name' => null, 'members' => 1]]);
        $byDirectorate = collect($data['by_directorate'])->keyBy(fn (array $row): string => $row['id'] ?? 'none');
        expect($byDirectorate[$this->directorate->id])->toMatchArray(['name' => 'DR Abidjan Nord', 'cgas' => 1, 'members' => 3, 'revenue' => 9_000_000])
            ->and($byDirectorate['none'])->toMatchArray(['cgas' => 1, 'members' => 2, 'revenue' => 1_000_000]);
    });

    it('donne la carte par DR et le détail par commune ; une DR inconnue n\'existe pas (404) ; réservé au CNCGA (403)', function () {
        $map = dashSpace($this->president)->getJson('/api/v1/network/statistics/map?period=year')->assertOk()->json('data');
        expect(collect($map['directorates'])->firstWhere('id', $this->directorate->id))->toMatchArray(['cgas' => 1, 'members' => 3, 'revenue' => 9_000_000]);

        dashSpace($this->president)->getJson("/api/v1/network/statistics/map/{$this->directorate->id}")->assertOk()
            ->assertJsonPath('data.directorate.name', 'DR Abidjan Nord')
            ->assertJsonPath('data.communes', [['id' => $this->commune->id, 'name' => 'Cocody', 'members' => 3]]);
        dashSpace($this->president)->getJson('/api/v1/network/statistics/map/'.Str::ulid())->assertNotFound();
        dashSpace($this->manager)->getJson('/api/v1/network/statistics')->assertForbidden();
        dashSpace($this->manager)->getJson('/api/v1/network/statistics/map')->assertForbidden();
    });
});

describe('position du CGA dans le réseau (F-STA-02)', function () {
    it('RG-STA-04 : compare ses indicateurs aux moyennes du réseau à partir de 5 CGA, sinon « Données insuffisantes »', function () {
        dashRow($this->cga, '2026-03-10', ['active_members' => 10, 'members_tee' => 10, 'fees_paid' => 1_000]);
        foreach ([20, 30, 40] as $members) {
            dashRow(Organization::factory()->cga()->create(), '2026-03-10', ['active_members' => $members, 'members_tee' => $members, 'fees_paid' => 1_000]);
        }

        dashSpace($this->manager)->getJson('/api/v1/cga/statistics')->assertOk()
            ->assertJsonPath('data.network', ['contributors' => 4, 'minimum' => 5, 'sufficient' => false, 'label' => 'Données insuffisantes'])
            ->assertJsonPath('data.indicators.active_members', ['own' => 10, 'network_average' => null]);

        dashRow(Organization::factory()->cga()->create(), '2026-03-10', ['active_members' => 50, 'members_tee' => 50, 'fees_paid' => 6_000]);
        dashSpace($this->manager)->getJson('/api/v1/cga/statistics')->assertOk()
            ->assertJsonPath('data.network.sufficient', true)
            ->assertJsonPath('data.network.label', null)
            ->assertJsonPath('data.indicators.active_members', ['own' => 10, 'network_average' => 30])
            ->assertJsonPath('data.indicators.fees_paid', ['own' => 1_000, 'network_average' => 2_000]);
        dashSpace($this->secretary)->getJson('/api/v1/cga/statistics')->assertForbidden();
        dashSpace($this->president)->getJson('/api/v1/cga/statistics')->assertForbidden();
    });
});

describe('bouton « Actualiser »', function () {
    it('programme le recalcul du CGA ou du réseau (202), au plus une fois toutes les 10 minutes (429)', function () {
        Queue::fake();

        dashSpace($this->manager)->postJson('/api/v1/cga/statistics/refresh')->assertStatus(202)->assertJsonPath('data', ['scope' => 'organization', 'organization_id' => $this->cga->id]);
        dashSpace($this->secretary)->postJson('/api/v1/cga/statistics/refresh')->assertStatus(429);
        dashSpace($this->president)->postJson('/api/v1/network/statistics/refresh')->assertStatus(202)->assertJsonPath('data.scope', 'network');
        $this->travel(11)->minutes();
        dashSpace($this->manager)->postJson('/api/v1/cga/statistics/refresh')->assertStatus(202);

        Queue::assertPushed(RefreshStatisticsJob::class, 3);
        Queue::assertPushed(RefreshStatisticsJob::class, fn (RefreshStatisticsJob $job): bool => $job->organizationId === null);
        $member = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => Member::factory()->active()->create(['organization_id' => $this->cga->id])->id]);
        dashSpace($member)->postJson('/api/v1/cga/statistics/refresh')->assertForbidden();
    });

    it('le Job recalcule le CGA, et journalise puis relance une erreur', function () {
        (new RefreshStatisticsJob($this->cga->id))->handle(app(RefreshNetworkStats::class));
        expect(DB::table('stat_org_daily')->where('organization_id', $this->cga->id)->count())->toBe(90);

        Log::spy();
        DB::statement('ALTER TABLE stat_org_daily RENAME TO stat_org_daily_unavailable');
        expect(fn () => (new RefreshStatisticsJob($this->cga->id))->handle(app(RefreshNetworkStats::class)))->toThrow(QueryException::class);
        Log::shouldHaveReceived('error')->once();
    });
});

describe('temps de réponse (critère de l\'étape 8.2)', function () {
    it('répond en moins de 500 ms sur un an et demi d\'agrégats de 20 CGA', function () {
        $cgas = Organization::factory()->cga()->count(19)->create()->push($this->cga);
        foreach ($cgas as $cga) {
            DB::statement("insert into stat_org_daily (organization_id, day, active_members, members_rme, members_tee, members_rsi, new_members, radiations, fees_due, fees_paid, tax_paid_total, tax_paid_by_nature, declared_revenue, members_by_sector, members_by_commune, concerns_opened, concerns_closed, concerns_responded, concerns_avg_response_hours, computed_at)
                select ?, day, 100, 40, 50, 10, 1, 0, 15000, 12000, 50000, '{\"patente\": 30000, \"tva\": 20000}', 50000000, '{\"none\": 100}', '{\"none\": 100}', 1, 1, 1, 12, now()
                from generate_series('2025-01-01'::date, '2026-03-31'::date, interval '1 day') as day", [$cga->id]);
        }

        foreach (['/api/v1/network/dashboard?period=year', '/api/v1/network/statistics?period=year'] as $url) {
            dashSpace($this->president)->getJson($url)->assertOk();
            $start = hrtime(true);
            dashSpace($this->president)->getJson($url)->assertOk();
            expect((hrtime(true) - $start) / 1e6)->toBeLessThan(500.0);
        }
    });
});
