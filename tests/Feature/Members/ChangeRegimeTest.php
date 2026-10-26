<?php

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRegimeChange;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Changement de régime (06-IMPLEMENTATION-PLAN étape 2.4, F-ADH-05, RG-ADH-08, 09) : historique daté,
 * justificatif, alerte « Radiation requise » au portefeuille CGA, jamais de blocage automatique.
 */

beforeEach(function () {
    Storage::fake();
});

function regimeAgent(Role $role = Role::CgaManager, ?string $organizationId = null): Membership
{
    return Membership::factory()->role($role)->create($organizationId === null ? [] : ['organization_id' => $organizationId]);
}

function regimeMember(Membership $agent, array $overrides = []): Member
{
    return Member::factory()->active()->create(['organization_id' => $agent->organization_id, ...$overrides]);
}

function changeRegime(Membership $agent, Member $member, array $overrides = []): TestResponse
{
    return inSpace($agent)->post("/api/v1/cga/members/{$member->id}/regimes", [
        'regime' => 'RSI',
        'effective_date' => '2026-09-15',
        'reason' => 'Reclassement notifié par la DGI',
        'justification' => UploadedFile::fake()->createWithContent('notification.pdf', "%PDF-1.4\n%%EOF"),
        ...$overrides,
    ], ['Accept' => 'application/json']);
}

it('RG-ADH-08 permet le passage au RSI au portefeuille CGA et ouvre l\'alerte « Radiation requise », historique daté', function () {
    $manager = regimeAgent();
    $member = regimeMember($manager);

    changeRegime($manager, $member)
        ->assertOk()
        ->assertJsonPath('data.regime', 'RSI')
        ->assertJsonPath('data.regime_since', '2026-09-15')
        ->assertJsonPath('data.rsi_since', '2026-09-15')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.attestation_blocked', false)
        ->assertJsonPath('data.alerts.0.code', 'radiation_required')
        ->assertJsonPath('data.alerts.0.label', 'Radiation requise — reclassement au régime réel')
        ->assertJsonPath('data.alerts.0.overdue', false)
        ->assertJsonPath('data.can.request_radiation', true);

    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}/regimes")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.regime', 'RSI')
        ->assertJsonPath('data.0.reason', 'Reclassement notifié par la DGI')
        ->assertJsonPath('data.0.justification.name', 'notification.pdf')
        ->assertJsonPath('data.0.author', $manager->user->fullName())
        ->assertJsonPath('data.1.regime', 'TEE')
        ->assertJsonPath('data.1.effective_date', '2026-01-01')
        ->assertJsonPath('data.1.reason', 'Régime à l\'inscription')
        ->assertJsonPath('data.1.justification', null);
    expect($member->radiations()->withoutGlobalScopes()->count())->toBe(0);
});

it('RG-ADH-09 signale l\'alerte en retard passé le délai paramétré, sans rien bloquer ni radier', function () {
    $manager = regimeAgent();
    $member = regimeMember($manager, ['regime' => 'RSI', 'regime_since' => today()->subDays(90)->toDateString(), 'rsi_since' => today()->subDays(90)->toDateString()]);

    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}")
        ->assertJsonPath('data.alerts.0.days', 90)
        ->assertJsonPath('data.alerts.0.overdue', true)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.attestation_blocked', false);

    app(SettingsService::class)->set('rsi.radiation_deadline_days', 120);
    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}")->assertJsonPath('data.alerts.0.overdue', false);
});

it('RG-ADH-08 garde l\'alerte du RSI au RNI et la referme au retour au RME ou au TEE', function () {
    $manager = regimeAgent();
    $member = regimeMember($manager, ['regime' => 'RSI', 'rsi_since' => '2026-01-01']);

    changeRegime($manager, $member, ['regime' => 'RNI', 'effective_date' => '2026-02-01'])->assertOk()->assertJsonPath('data.rsi_since', '2026-01-01');
    changeRegime($manager, $member, ['regime' => 'TEE', 'effective_date' => '2026-03-01'])
        ->assertOk()
        ->assertJsonPath('data.rsi_since', null)
        ->assertJsonPath('data.alerts', []);
});

it('change le régime d\'un dossier Cabinet sans alerte, et laisse le comptable le faire', function () {
    $manager = regimeAgent();
    $member = regimeMember($manager, ['portfolio' => 'cabinet', 'membership_number' => null, 'client_number' => 'CAB-TST-00001']);

    changeRegime(regimeAgent(Role::CgaAccountant, $manager->organization_id), $member)
        ->assertOk()
        ->assertJsonPath('data.regime', 'RSI')
        ->assertJsonPath('data.rsi_since', null)
        ->assertJsonPath('data.alerts', []);
});

it('refuse un régime inchangé, une date d\'effet antérieure ou future, l\'absence de justificatif', function () {
    $manager = regimeAgent();
    $member = regimeMember($manager);

    changeRegime($manager, $member, ['regime' => 'TEE'])->assertUnprocessable()->assertJsonPath('errors.regime.0', 'Choisissez un régime différent du régime actuel.');
    changeRegime($manager, $member, ['effective_date' => '2025-12-31'])->assertUnprocessable()
        ->assertJsonPath('errors.effective_date.0', 'La date d\'effet ne peut pas précéder celle du régime actuel (01/01/2026).');
    changeRegime($manager, $member, ['effective_date' => today()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('effective_date');
    changeRegime($manager, $member, ['justification' => null])->assertUnprocessable()->assertJsonValidationErrors('justification');
    changeRegime($manager, $member, ['regime' => 'XYZ', 'reason' => ''])->assertUnprocessable()->assertJsonValidationErrors(['regime', 'reason']);

    expect(app(TenantContext::class)->runAs($manager, fn () => MemberRegimeChange::query()->count()))->toBe(0);
});

it('refuse un brouillon, un dossier radié, un rôle sans le droit et un dossier d\'un autre CGA', function () {
    $manager = regimeAgent();

    changeRegime($manager, Member::factory()->create(['organization_id' => $manager->organization_id]))->assertStatus(409)->assertJsonPath('code', 'member_draft');
    changeRegime($manager, regimeMember($manager, ['status' => MemberStatus::Radiated]))->assertStatus(409)->assertJsonPath('code', 'member_closed');
    changeRegime(regimeAgent(Role::CgaBillingOfficer, $manager->organization_id), regimeMember($manager))->assertForbidden();
    changeRegime($manager, Member::factory()->active()->create())->assertNotFound();
    inSpace(regimeAgent(Role::CgaBillingOfficer, $manager->organization_id))->getJson('/api/v1/cga/members/'.regimeMember($manager)->id.'/regimes')->assertOk();
    inSpace(regimeAgent())->getJson('/api/v1/cga/members/'.regimeMember($manager)->id.'/regimes')->assertNotFound();
});
