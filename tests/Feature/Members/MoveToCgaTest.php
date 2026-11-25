<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Members\Actions\CreateExpectedPieces;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\PortfolioMovement;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Bascules entre portefeuilles (06-IMPLEMENTATION-PLAN étape 2.14, RG-PTF-03 à 05, 08) : Cabinet → CGA
 * par « Basculer vers le portefeuille CGA », CGA → Cabinet par une radiation validée, historisées.
 */

beforeEach(function () {
    Storage::fake();
});

function moveManager(): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create([
        'organization_id' => Organization::factory()->create(['numbering_abbreviation' => 'DCK', 'abbreviation_locked_at' => now()])->id,
    ]);
}

/**
 * Client actif du portefeuille Cabinet, avec ses pièces attendues.
 */
function cabinetClient(Membership $manager, array $overrides = []): Member
{
    $member = Member::factory()->active()->cabinet()->create([
        'organization_id' => $manager->organization_id, 'membership_number' => null, 'client_number' => 'CAB-DCK-'.fake()->unique()->numerify('#####'),
        'cga_contract_signed_on' => null, ...$overrides,
    ]);
    app(TenantContext::class)->runAs($manager, fn () => app(CreateExpectedPieces::class)->execute($member));

    return $member;
}

function moveToCga(Membership $agent, Member $member, array $overrides = []): TestResponse
{
    return inSpace($agent)->post("/api/v1/cga/members/{$member->id}/portfolio/cga", [
        'cga_contract_signed_on' => '2026-09-01', 'cga_contract_confirmed' => '1',
        // Droit d'adhésion de la fabrique (25 000 F HT), TVA de 18 % en sus (étape 3.15).
        'entry_fee_payment' => ['amount' => 29_500, 'paid_on' => '2026-09-01', 'method' => 'cash'],
        ...$overrides,
    ], ['Accept' => 'application/json']);
}

it('RG-PTF-03 bascule un client TEE du Cabinet au portefeuille CGA : n° CGA Connect, contrat, apporteur, bascule historisée', function () {
    $manager = moveManager();
    $referrer = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id, 'label' => 'Accessoire 3']);
    $member = cabinetClient($manager, ['client_number' => 'CAB-DCK-00007']);

    moveToCga($manager, $member, ['contract' => UploadedFile::fake()->createWithContent('contrat.pdf', "%PDF-1.4\n%%EOF"), 'referrer_id' => $referrer->id])
        ->assertOk()
        ->assertJsonPath('data.portfolio', 'cga')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.membership_number', '2026-DCK-0001')
        ->assertJsonPath('data.client_number', 'CAB-DCK-00007')
        ->assertJsonPath('data.adhesion_date', '2026-09-01')
        ->assertJsonPath('data.contract.signed_on', '2026-09-01')
        ->assertJsonPath('data.contract.confirmed', true)
        ->assertJsonPath('data.referrer.label', 'Accessoire 3')
        ->assertJsonPath('data.can.move_to_cga', false)
        ->assertJsonPath('data.can.request_radiation', true);

    $movement = app(TenantContext::class)->runAs($manager, fn () => PortfolioMovement::query()->where('member_id', $member->id)->sole());
    $contract = app(TenantContext::class)->runAs($manager, fn () => $member->pieces()->where('piece_type', PieceType::SignedCgaContract)->sole());
    expect($movement->direction->value)->toBe('cabinet_to_cga')
        ->and($movement->effective_date->toDateString())->toBe('2026-09-01')
        ->and($movement->contract_signed_on?->toDateString())->toBe('2026-09-01')
        ->and($movement->contract_file_id)->not->toBeNull()
        ->and($movement->reason)->toBe('Signature du contrat CGA')
        ->and($movement->created_by)->toBe($manager->user_id)
        ->and($contract->status)->toBe(PieceStatus::Received)
        ->and(AuditLog::query()->where('event', 'portfolio_moved')->where('subject_id', $member->id)->value('context'))
        ->toEqual(['direction' => 'cabinet_to_cga', 'number' => '2026-DCK-0001']);
});

it('RG-PTF-03 RG-ADH-02 encaisse le droit d\'adhésion avec la bascule : somme due TTC ouverte et payée, reçu numéroté ; un droit à zéro n\'exige rien', function () {
    $manager = moveManager();
    $member = cabinetClient($manager);

    moveToCga($manager, $member, ['entry_fee_payment' => ['amount' => 29_500, 'paid_on' => '2026-09-01', 'method' => 'mobile_money', 'reference' => 'OM-PTF']])->assertOk();

    $charge = app(TenantContext::class)->runAs($manager, fn () => MemberCharge::query()->where('member_id', $member->id)->sole());
    $payment = app(TenantContext::class)->runAs($manager, fn () => MemberPayment::query()->where('member_id', $member->id)->sole());
    expect($charge->kind)->toBe(ChargeKind::EntryFee)
        ->and($charge->amount_excl_tax)->toBe(25_000)
        ->and($charge->amount)->toBe(29_500)
        ->and($charge->status)->toBe(ChargeStatus::Paid)
        ->and($payment->status)->toBe(PaymentStatus::Confirmed)
        ->and($payment->reference)->toBe('OM-PTF')
        ->and($payment->receipt_number)->toBe('REC-DCK-'.today()->year.'-00001');

    $free = cabinetClient($manager, ['entry_fee_amount' => 0]);
    moveToCga($manager, $free, ['entry_fee_payment' => null])->assertOk()->assertJsonPath('data.portfolio', 'cga');
    expect(app(TenantContext::class)->runAs($manager, fn () => MemberCharge::query()->where('member_id', $free->id)->count()))->toBe(0);
});

it('RG-PTF-08 garde le contrat signé scanné comme pièce attendue s\'il n\'est pas joint', function () {
    $manager = moveManager();
    $member = cabinetClient($manager);

    moveToCga($manager, $member, ['reason' => 'Retour au régime TEE'])->assertOk()->assertJsonPath('data.pieces_status', 'pending');

    expect(app(TenantContext::class)->runAs($manager, fn () => $member->pieces()->where('piece_type', PieceType::SignedCgaContract)->sole())->status)->toBe(PieceStatus::Expected)
        ->and(app(TenantContext::class)->runAs($manager, fn () => PortfolioMovement::query()->where('member_id', $member->id)->sole())->reason)->toBe('Retour au régime TEE');
});

it('RG-PTF-03 refuse le régime réel, un contrat non confirmé, le droit d\'adhésion impayé, l\'exclusivité et un rôle sans le droit', function () {
    $manager = moveManager();
    $member = cabinetClient($manager);

    moveToCga($manager, cabinetClient($manager, ['regime' => 'RSI']))->assertUnprocessable()
        ->assertJsonPath('errors.regime.0', 'Régime RSI : seul un dossier au RME ou au TEE entre au portefeuille CGA.');
    moveToCga($manager, $member, ['cga_contract_confirmed' => '0', 'cga_contract_signed_on' => today()->addDay()->toDateString()])->assertUnprocessable()
        ->assertJsonValidationErrors(['cga_contract_confirmed', 'cga_contract_signed_on']);
    moveToCga($manager, $member, ['referrer_id' => BusinessReferrer::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('referrer_id');
    moveToCga($manager, Member::factory()->active()->create(['organization_id' => $manager->organization_id]))->assertStatus(409)->assertJsonPath('code', 'portfolio_already_cga');
    moveToCga($manager, Member::factory()->cabinet()->create(['organization_id' => $manager->organization_id]))->assertStatus(409)->assertJsonPath('code', 'member_not_active');
    moveToCga(Membership::factory()->role(Role::CgaAccountant)->create(['organization_id' => $manager->organization_id]), $member)->assertForbidden();

    moveToCga($manager, $member, ['entry_fee_payment' => null])->assertStatus(409)
        ->assertJsonPath('code', 'membership_incomplete')
        ->assertJsonPath('details.missing', ['entry_fee_payment'])
        ->assertJsonPath('details.balance', 29_500);
    moveToCga($manager, $member, ['entry_fee_payment' => ['amount' => 10_000, 'paid_on' => '2026-09-01', 'method' => 'cash']])->assertStatus(409)
        ->assertJsonPath('details.balance', 19_500);
    moveToCga($manager, $member, ['entry_fee_payment' => ['amount' => 30_000, 'paid_on' => '2026-09-01', 'method' => 'cash']])->assertUnprocessable()->assertJsonValidationErrors('amount');
    moveToCga($manager, $member, ['entry_fee_payment' => ['amount' => 0, 'paid_on' => today()->addDay()->toDateString(), 'method' => 'troc', 'extra' => 'x']])->assertUnprocessable()
        ->assertJsonValidationErrors(['entry_fee_payment', 'entry_fee_payment.amount', 'entry_fee_payment.paid_on', 'entry_fee_payment.method']);
    expect(app(TenantContext::class)->runAs($manager, fn () => MemberPayment::query()->where('member_id', $member->id)->count()))->toBe(0)
        ->and(app(TenantContext::class)->runAs($manager, fn () => MemberCharge::query()->where('member_id', $member->id)->count()))->toBe(0);

    Member::factory()->active()->create(['ncc' => $member->ncc]);
    moveToCga($manager, $member)->assertStatus(409)->assertJsonPath('code', 'membership_exclusive');
    expect($member->fresh()?->portfolio->value)->toBe('cabinet');
});

it('RG-PTF-04 RG-PTF-05 va du CGA au Cabinet et revient : deux bascules historisées, ancienne radiation close, nouvelle radiation possible', function () {
    $manager = moveManager();
    $referrer = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id]);
    $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'referrer_id' => $referrer->id, 'regime' => 'TEE']);
    $radiate = fn (array $input) => inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/radiation", [
        'initiator' => 'cga', 'reason' => 'reclassified_real_regime', 'effective_date' => '2026-09-30', 'observation' => 'Reclassement.', ...$input,
    ]);

    $radiate([])->assertCreated();
    inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/radiation/validate", ['outcome' => 'moved_to_cabinet'])->assertOk()->assertJsonPath('data.portfolio', 'cabinet');

    moveToCga($manager, $member, ['cga_contract_signed_on' => today()->toDateString()])
        ->assertOk()
        ->assertJsonPath('data.portfolio', 'cga')
        ->assertJsonPath('data.radiation', null);
    inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/radiation/cancel", ['reason' => 'Erreur'])->assertStatus(409)->assertJsonPath('code', 'radiation_not_found');

    $radiate(['reason' => 'death', 'effective_date' => today()->toDateString(), 'validate_now' => true, 'outcome' => 'closed'])->assertCreated()->assertJsonPath('data.status', 'radiated');

    $directions = app(TenantContext::class)->runAs($manager, fn () => PortfolioMovement::query()->where('member_id', $member->id)->orderBy('created_at')->pluck('direction')->map->value->all());
    expect($directions)->toBe(['cga_to_cabinet', 'cabinet_to_cga'])
        ->and($member->fresh()?->status)->toBe(MemberStatus::Radiated);
});
