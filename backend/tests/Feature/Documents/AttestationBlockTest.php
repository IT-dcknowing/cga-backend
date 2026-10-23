<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Support\AuthSession;
use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Blocage manuel de l'attestation (06-IMPLEMENTATION-PLAN étape 3.7, RG-DOC-10, RG-DOC-07) : le gérant
 * bloque, sur alerte ou de sa propre initiative, avec un motif ; l'attestation est alors refusée avec
 * ce motif ; il débloque avec un motif ; tout est historisé. Jamais de blocage automatique (CR-04).
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync']);
    fakePdfConverter();
});

/**
 * @param  array<string, mixed>  $payload
 */
function blockAttestation(Membership $agent, Member $member, array $payload, string $action = ''): TestResponse
{
    return inSpace($agent)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()])->postJson("/api/v1/cga/members/{$member->id}/attestation-blocks{$action}", $payload);
}

it('RG-DOC-10 bloque l\'attestation avec un motif : refus de l\'attestation avec ce motif (RG-DOC-07), puis déblocage motivé, historisé', function () {
    $manager = documentAgent();
    $member = validatedMember($manager);

    blockAttestation($manager, $member, ['trigger' => 'manual', 'reason' => 'Dossier en litige'])->assertCreated()
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.trigger', 'manual')
        ->assertJsonPath('data.trigger_label', 'Décision du gérant')
        ->assertJsonPath('data.reason', 'Dossier en litige')
        ->assertJsonPath('data.blocked_by.id', $manager->user_id);

    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}")->assertOk()
        ->assertJsonPath('data.attestation_blocked', true)
        ->assertJsonPath('data.attestation_block.reason', 'Dossier en litige')
        ->assertJsonPath('data.can.block_attestation', false)
        ->assertJsonPath('data.can.release_attestation', true);
    inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/documents", ['type' => 'membership_certificate'])->assertStatus(409)
        ->assertJsonPath('code', 'attestation_blocked')
        ->assertJsonPath('details.reason', 'Dossier en litige');
    blockAttestation($manager, $member, ['trigger' => 'manual', 'reason' => 'Encore'])->assertStatus(409)->assertJsonPath('code', 'attestation_already_blocked');

    blockAttestation($manager, $member, ['reason' => 'Litige réglé'], '/release')->assertOk()
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('data.unblock_reason', 'Litige réglé')
        ->assertJsonPath('data.unblocked_by.id', $manager->user_id);
    blockAttestation($manager, $member, ['reason' => 'Encore'], '/release')->assertStatus(409)->assertJsonPath('code', 'attestation_not_blocked');
    inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/documents", ['type' => 'membership_certificate'])->assertStatus(202);

    blockAttestation($manager, $member, ['trigger' => 'manual', 'reason' => 'Nouveau litige'])->assertCreated();
    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}/attestation-blocks")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.reason', 'Nouveau litige')
        ->assertJsonPath('data.1.unblock_reason', 'Litige réglé');
    expect(AuditLog::query()->where('subject_type', (new AttestationBlock)->getMorphClass())->count())->toBeGreaterThanOrEqual(3);
});

it('RG-DOC-10 bloque sur une alerte seulement si elle est en retard (honoraires impayés, régime réel non radié)', function () {
    $manager = documentAgent();
    $member = validatedMember($manager);
    $this->travelTo('2026-05-10');

    blockAttestation($manager, $member, ['trigger' => 'fees_overdue', 'reason' => 'Impayés'])->assertUnprocessable()->assertJsonValidationErrors('trigger');
    MemberCharge::factory()->fees()->create(['member_id' => $member->id, 'due_date' => '2026-01-01', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'status' => ChargeStatus::Overdue, 'overdue_since' => '2026-01-31']);
    blockAttestation($manager, $member, ['trigger' => 'fees_overdue', 'reason' => 'Impayés depuis janvier'])->assertCreated()->assertJsonPath('data.trigger', 'fees_overdue');

    $rsi = validatedMember($manager, ['regime' => 'RSI', 'rsi_since' => '2026-05-01']);
    blockAttestation($manager, $rsi, ['trigger' => 'rsi_overdue', 'reason' => 'RSI'])->assertUnprocessable()->assertJsonValidationErrors('trigger');
    $late = validatedMember($manager, ['regime' => 'RSI', 'rsi_since' => '2026-01-02']);
    blockAttestation($manager, $late, ['trigger' => 'rsi_overdue', 'reason' => 'RSI non radié'])->assertCreated();
});

it('RG-DOC-10 réservé au gérant ; motif et origine obligatoires ; dossier validé du portefeuille CGA seulement', function () {
    $manager = documentAgent();
    $member = validatedMember($manager);

    foreach ([Role::CgaAccountantExpert, Role::CgaAccountant, Role::CgaSecretary] as $role) {
        $agent = documentAgent($role, $manager->organization_id);
        blockAttestation($agent, $member, ['trigger' => 'manual', 'reason' => 'X'])->assertForbidden();
        blockAttestation($agent, $member, ['reason' => 'X'], '/release')->assertForbidden();
        inSpace($agent)->getJson("/api/v1/cga/members/{$member->id}/attestation-blocks")->assertOk();
    }
    blockAttestation($manager, $member, ['trigger' => 'auto', 'reason' => ''])->assertUnprocessable()->assertJsonValidationErrors(['trigger', 'reason']);
    blockAttestation($manager, $member, ['trigger' => 'manual', 'reason' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors('reason');
    blockAttestation($manager, $member, [], '/release')->assertUnprocessable()->assertJsonValidationErrors('reason');

    $cabinet = Member::factory()->active()->cabinet()->create(['organization_id' => $manager->organization_id]);
    $radiated = validatedMember($manager, ['status' => MemberStatus::Radiated]);
    foreach ([$cabinet, $radiated] as $other) {
        blockAttestation($manager, $other, ['trigger' => 'manual', 'reason' => 'X'])->assertStatus(409)->assertJsonPath('code', 'attestation_block_unavailable');
    }
    inSpace($manager)->getJson("/api/v1/cga/members/{$cabinet->id}")->assertJsonPath('data.can.block_attestation', false);
    inSpace(documentAgent(Role::CgaAccountant, $manager->organization_id))->getJson("/api/v1/cga/members/{$member->id}")->assertJsonPath('data.can.block_attestation', false);

    blockAttestation($manager, validatedMember($manager, ['status' => MemberStatus::RadiationPending]), ['trigger' => 'manual', 'reason' => 'X'])->assertCreated();
});
