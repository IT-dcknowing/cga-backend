<?php

use App\Domain\Auth\Support\AuthSession;
use App\Domain\Members\Actions\ImportMember;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Events\PaymentConfirmed;
use App\Domain\Payments\Events\PaymentDeclared;
use App\Domain\Payments\Events\PaymentRejected;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Droit d'adhésion, honoraires et paiements (06-IMPLEMENTATION-PLAN étape 3.2, F-PAY-01 à 03,
 * RG-PAY-01, 02) : barème, somme due « droit d'adhésion », paiement saisi par le CGA, déclaré par
 * l'adhérent, confirmé ou rejeté, ventilation, reçus numérotés.
 */

beforeEach(function () {
    Storage::fake();
});

/**
 * Espace de l'agent, actif à l'instant (le délai d'inactivité de la session suit les voyages dans le temps).
 */
function paymentsSpace(Membership $agent): TestCase
{
    return inSpace($agent)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()]);
}

function paymentsManager(string $abbreviation = 'DCK'): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create([
        'organization_id' => Organization::factory()->create(['numbering_abbreviation' => $abbreviation])->id,
    ]);
}

function paymentsAgent(Membership $manager, Role $role): Membership
{
    return Membership::factory()->role($role)->create(['organization_id' => $manager->organization_id]);
}

/**
 * Brouillon du portefeuille CGA inscrit par l'API : son droit d'adhésion est ouvert.
 */
function enrolledDraft(Membership $manager, array $overrides = []): Member
{
    $id = paymentsSpace($manager)->postJson('/api/v1/cga/members', [
        'portfolio' => 'cga', 'entity_type' => 'company', 'company_name' => 'Ets Paiements', 'legal_form' => 'SARL',
        'ncc' => fake()->unique()->numerify('#######').'P', 'regime' => 'TEE', 'main_activity' => 'Commerce', 'phone' => '0707070707', ...$overrides,
    ])->assertCreated()->json('data.id');

    return Member::query()->withoutGlobalScopes()->findOrFail($id);
}

function memberCharges(Membership $agent, Member $member): array
{
    return app(TenantContext::class)->runAs($agent, fn () => MemberCharge::query()->where('member_id', $member->id)->orderBy('due_date')->get()->all());
}

function recordPayment(Membership $agent, Member $member, array $overrides = []): TestResponse
{
    return paymentsSpace($agent)->post("/api/v1/cga/members/{$member->id}/payments", [
        'amount' => 25_000, 'paid_on' => today()->toDateString(), 'method' => 'cash', ...$overrides,
    ], ['Accept' => 'application/json']);
}

function declarePayment(Membership $owner, array $overrides = []): TestResponse
{
    return paymentsSpace($owner)->post('/api/v1/member/payments', [
        'amount' => 25_000, 'paid_on' => today()->toDateString(), 'method' => 'mobile_money', 'reference' => 'OM-778899',
        'proof' => UploadedFile::fake()->createWithContent('preuve.pdf', "%PDF-1.4\n%%EOF"), ...$overrides,
    ], ['Accept' => 'application/json']);
}

function memberOwner(Member $member): Membership
{
    return Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $member->organization_id, 'member_id' => $member->id]);
}

describe('barème (F-PAY-01)', function () {
    it('RG-PAY-01 propose le barème à l\'inscription sans changer les montants des adhérents déjà inscrits', function () {
        $manager = paymentsManager();
        paymentsSpace($manager)->getJson('/api/v1/cga/fee-schedule')
            ->assertOk()
            ->assertJsonPath('data.entry_fee.amount', 25_000)
            ->assertJsonPath('data.entry_fee.valid_from', null)
            ->assertJsonPath('data.fees.TEE.amount', 0)
            ->assertJsonPath('data.fees.TEE.periodicity', 'monthly')
            ->assertJsonPath('data.can.update', true);
        $before = enrolledDraft($manager);

        paymentsSpace($manager)->putJson('/api/v1/cga/fee-schedule', [
            'entry_fee' => 30_000,
            'fees' => ['RME' => ['amount' => 10_000, 'periodicity' => 'quarterly'], 'TEE' => ['amount' => 20_000, 'periodicity' => 'monthly']],
        ])->assertOk()
            ->assertJsonPath('data.entry_fee.amount', 30_000)
            ->assertJsonPath('data.entry_fee.valid_from', today()->toDateString())
            ->assertJsonPath('data.fees.RME.periodicity', 'quarterly');

        $after = enrolledDraft($manager, ['regime' => 'RME']);
        expect($after->entry_fee_amount)->toBe(30_000)
            ->and($after->fees_amount)->toBe(10_000)
            ->and($after->fees_periodicity->value)->toBe('quarterly')
            ->and($before->fresh()?->entry_fee_amount)->toBe(25_000)
            ->and(memberCharges($manager, $before)[0]->amount_excl_tax)->toBe(25_000)
            ->and(memberCharges($manager, $before)[0]->amount)->toBe(29_500);
    });

    it('lit le barème avec le droit d\'inscrire, le modifie avec celui de paramétrer le centre', function () {
        $manager = paymentsManager();
        $schedule = ['entry_fee' => 1, 'fees' => ['RME' => ['amount' => 1, 'periodicity' => 'monthly'], 'TEE' => ['amount' => 1, 'periodicity' => 'yearly']]];

        paymentsSpace(paymentsAgent($manager, Role::CgaSecretary))->getJson('/api/v1/cga/fee-schedule')->assertOk()->assertJsonPath('data.can.update', false);
        paymentsSpace(paymentsAgent($manager, Role::CgaBillingOfficer))->getJson('/api/v1/cga/fee-schedule')->assertForbidden();
        paymentsSpace(paymentsAgent($manager, Role::CgaAccountantExpert))->putJson('/api/v1/cga/fee-schedule', $schedule)->assertForbidden();
        paymentsSpace($manager)->putJson('/api/v1/cga/fee-schedule', ['entry_fee' => -1, 'fees' => ['TEE' => ['amount' => 'x']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['entry_fee', 'fees.RME', 'fees.TEE.amount', 'fees.TEE.periodicity']);
        paymentsSpace($manager)->putJson('/api/v1/cga/fee-schedule', $schedule)->assertOk();
        paymentsSpace($manager)->putJson('/api/v1/cga/fee-schedule', $schedule)->assertOk()->assertJsonPath('data.fees.TEE.periodicity', 'yearly');
    });
});

describe('droit d\'adhésion (F-PAY-02)', function () {
    it('ouvre le droit d\'adhésion du portefeuille CGA à l\'inscription, l\'ajuste, l\'annule avec le brouillon', function () {
        $manager = paymentsManager();
        $member = enrolledDraft($manager, ['entry_fee_amount' => 40_000]);
        $cabinet = enrolledDraft($manager, ['portfolio' => 'cabinet']);

        expect(memberCharges($manager, $cabinet))->toBe([])
            ->and(memberCharges($manager, $member)[0]->kind->value)->toBe('entry_fee')
            ->and(memberCharges($manager, $member)[0]->amount_excl_tax)->toBe(40_000)
            ->and(memberCharges($manager, $member)[0]->tax_amount)->toBe(7_200)
            ->and(memberCharges($manager, $member)[0]->amount)->toBe(47_200)
            ->and(memberCharges($manager, $member)[0]->status)->toBe(ChargeStatus::Due);

        paymentsSpace($manager)->putJson("/api/v1/cga/members/{$member->id}", [
            'entity_type' => 'company', 'company_name' => 'Ets Paiements', 'legal_form' => 'SARL', 'ncc' => $member->ncc, 'regime' => 'TEE',
            'main_activity' => 'Commerce', 'phone' => '0707070707', 'entry_fee_amount' => 35_000,
        ])->assertOk();
        expect(memberCharges($manager, $member)[0]->amount)->toBe(41_300);

        paymentsSpace($manager)->getJson("/api/v1/cga/members/{$member->id}/charges")->assertOk()
            ->assertJsonPath('data.0.amount_excl_tax', 35_000)
            ->assertJsonPath('data.0.tax_rate', 18)
            ->assertJsonPath('data.0.tax_amount', 6_300)
            ->assertJsonPath('data.0.balance', 41_300)
            ->assertJsonPath('data.0.kind_label', 'Droit d\'adhésion');
        paymentsSpace($manager)->deleteJson("/api/v1/cga/members/{$member->id}")->assertNoContent();
        expect(memberCharges($manager, $member)[0]->status)->toBe(ChargeStatus::Cancelled);
    });
});

describe('paiement saisi par le CGA (F-PAY-02)', function () {
    it('RG-PAY-02 confirme le paiement d\'office, le ventile et numérote le reçu par centre et par année', function () {
        Event::fake([PaymentConfirmed::class]);
        $manager = paymentsManager();
        $member = enrolledDraft($manager);

        recordPayment($manager, $member, ['amount' => 10_000, 'reference' => 'CAISSE-1', 'proof' => UploadedFile::fake()->createWithContent('bordereau.pdf', "%PDF-1.4\n%%EOF")])
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.source', 'cga_entry')
            ->assertJsonPath('data.receipt_number', 'REC-DCK-'.today()->year.'-00001')
            ->assertJsonPath('data.allocations.0.amount', 10_000)
            ->assertJsonPath('data.proof.name', 'bordereau.pdf')
            ->assertJsonPath('data.can.confirm', false);
        expect(memberCharges($manager, $member)[0]->status)->toBe(ChargeStatus::Partial);

        recordPayment($manager, $member, ['amount' => 19_500, 'method' => 'transfer'])->assertCreated()->assertJsonPath('data.receipt_number', 'REC-DCK-'.today()->year.'-00002');
        expect(memberCharges($manager, $member)[0]->status)->toBe(ChargeStatus::Paid)
            ->and(memberCharges($manager, $member)[0]->amount_paid)->toBe(29_500);
        Event::assertDispatchedTimes(PaymentConfirmed::class, 2);

        $this->travelTo(today()->addYear()->startOfYear()->addDay());
        $next = enrolledDraft($manager);
        recordPayment($manager, $next, ['paid_on' => today()->toDateString()])->assertCreated()->assertJsonPath('data.receipt_number', 'REC-DCK-'.today()->year.'-00001');
    });

    it('ventile sur les échéances choisies, ou à défaut sur les plus anciennes, sans dépasser le reste dû', function () {
        $manager = paymentsManager();
        $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id]);
        $older = MemberCharge::factory()->fees()->create(['member_id' => $member->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-08-10']);
        $newer = MemberCharge::factory()->fees()->create(['member_id' => $member->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-09-10']);
        $other = MemberCharge::factory()->create();

        recordPayment($manager, $member, ['amount' => 20_000])->assertCreated()
            ->assertJsonPath('data.allocations.0.charge_id', $older->id)
            ->assertJsonPath('data.allocations.0.amount', 15_000)
            ->assertJsonPath('data.allocations.1.amount', 5_000);
        recordPayment($manager, $member, ['amount' => 10_001, 'charge_ids' => [$newer->id]])->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Le montant dépasse le reste dû des sommes choisies (10 000 F CFA).');
        recordPayment($manager, $member, ['amount' => 1, 'charge_ids' => [$other->id]])->assertUnprocessable()->assertJsonValidationErrors('charge_ids');
        recordPayment($manager, $member, ['amount' => 10_000, 'charge_ids' => [$newer->id]])->assertCreated();
        recordPayment($manager, $member, ['amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('amount');

        paymentsSpace($manager)->getJson("/api/v1/cga/members/{$member->id}/payments")->assertOk()->assertJsonCount(2, 'data');
    });

    it('réserve la saisie à ceux qui gèrent les paiements, refuse une saisie invalide et un dossier d\'un autre CGA', function () {
        $manager = paymentsManager();
        $member = enrolledDraft($manager);

        recordPayment(paymentsAgent($manager, Role::CgaSecretary), $member)->assertForbidden();
        recordPayment(paymentsAgent($manager, Role::CgaBillingOfficer), $member, ['amount' => 5_000])->assertCreated();
        recordPayment($manager, $member, ['amount' => 0, 'paid_on' => today()->addDay()->toDateString(), 'method' => 'troc'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount', 'paid_on', 'method']);
        recordPayment(paymentsManager('AUT'), $member)->assertNotFound();
        paymentsSpace(paymentsManager('AUX'))->getJson("/api/v1/cga/members/{$member->id}/payments")->assertNotFound();
        paymentsSpace(paymentsAgent($manager, Role::CgaTrainer))->getJson("/api/v1/cga/members/{$member->id}/payments")->assertOk();

        app(TenantContext::class)->runAs($manager, fn () => Organization::query()->whereKey($manager->organization_id)->update(['numbering_abbreviation' => null]));
        recordPayment($manager, $member, ['amount' => 1_000])->assertStatus(409)->assertJsonPath('code', 'abbreviation_missing');
    });
});

describe('paiement déclaré par l\'adhérent (F-PAY-03)', function () {
    it('enregistre la déclaration avec sa preuve, à confirmer, et la montre à l\'adhérent seul', function () {
        Event::fake([PaymentDeclared::class]);
        $manager = paymentsManager();
        $member = enrolledDraft($manager);
        $owner = memberOwner($member);

        declarePayment($owner)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_confirmation')
            ->assertJsonPath('data.source', 'member_declaration')
            ->assertJsonPath('data.receipt_number', null)
            ->assertJsonPath('data.allocations', [])
            ->assertJsonPath('data.can.confirm', false);
        Event::assertDispatched(PaymentDeclared::class);

        declarePayment($owner, ['proof' => null])->assertUnprocessable()->assertJsonValidationErrors('proof');
        paymentsSpace($owner)->getJson('/api/v1/member/payments')->assertOk()->assertJsonCount(1, 'data');
        paymentsSpace($owner)->getJson('/api/v1/member/charges')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'due');
        paymentsSpace(memberOwner(enrolledDraft($manager)))->getJson('/api/v1/member/payments')->assertOk()->assertJsonCount(0, 'data');
        declarePayment($manager)->assertForbidden();
        paymentsSpace($manager)->getJson('/api/v1/member/charges')->assertForbidden();
    });

    it('le CGA confirme une déclaration (reçu) ou la rejette avec motif ; une seule fois', function () {
        Event::fake([PaymentRejected::class]);
        $manager = paymentsManager();
        $member = enrolledDraft($manager);
        $owner = memberOwner($member);
        $declared = declarePayment($owner, ['amount' => 29_500])->json('data.id');
        $wrong = declarePayment($owner, ['amount' => 1_000])->json('data.id');
        $charge = memberCharges($manager, $member)[0];

        paymentsSpace($manager)->getJson('/api/v1/cga/payments?filter[status]=pending_confirmation')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.member.id', $member->id)
            ->assertJsonPath('data.0.can.confirm', true);

        paymentsSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/payments/{$declared}/confirm", ['charge_ids' => [$charge->id]])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.receipt_number', 'REC-DCK-'.today()->year.'-00001');
        paymentsSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/payments/{$declared}/confirm")->assertStatus(409)->assertJsonPath('code', 'payment_not_pending');

        paymentsSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/payments/{$wrong}/reject", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        paymentsSpace(paymentsAgent($manager, Role::CgaSecretary))->postJson("/api/v1/cga/members/{$member->id}/payments/{$wrong}/reject", ['reason' => 'x'])->assertForbidden();
        paymentsSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/payments/{$wrong}/reject", ['reason' => 'Preuve illisible'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Preuve illisible');
        Event::assertDispatched(PaymentRejected::class);
        paymentsSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/payments/{$wrong}/reject", ['reason' => 'Encore'])->assertStatus(409);
        paymentsSpace($manager)->postJson('/api/v1/cga/members/'.enrolledDraft($manager)->id."/payments/{$wrong}/confirm")->assertNotFound();

        expect(memberCharges($manager, $member)[0]->status)->toBe(ChargeStatus::Paid)
            ->and(app(TenantContext::class)->runAs($manager, fn () => MemberPayment::query()->where('status', 'confirmed')->count()))->toBe(1);
        paymentsSpace(paymentsAgent($manager, Role::CgaSecretary))->getJson('/api/v1/cga/payments')->assertForbidden();
    });
});

it('RG-IMP-03 tient pour acquitté le droit d\'adhésion d\'un dossier repris actif', function () {
    $manager = paymentsManager();
    $member = app(TenantContext::class)->runAs($manager, fn () => app(ImportMember::class)->execute(
        $manager->organization_id, Portfolio::Cga,
        ['entity_type' => 'company', 'company_name' => 'Repris', 'legal_form' => 'SARL', 'ncc' => '1234123R', 'regime' => 'TEE', 'main_activity' => 'Commerce', 'phone' => '0707070707', 'cga_contract_signed_on' => '2024-01-01'],
        CarbonImmutable::parse('2024-01-01'), $manager->user,
    ));

    expect($member->status)->toBe(MemberStatus::Active)
        ->and(memberCharges($manager, $member)[0]->status)->toBe(ChargeStatus::Paid)
        ->and(memberCharges($manager, $member)[0]->amount_paid)->toBe(29_500);
});

it('applique le taux de TVA réglé par le CGA (invoices.vat_rate) aux sommes dues ouvertes ensuite', function () {
    $manager = paymentsManager();
    app(SettingsService::class)->set('invoices.vat_rate', 0, $manager->organization_id);

    $member = enrolledDraft($manager, ['entry_fee_amount' => 40_000]);

    $other = paymentsManager('AUT');

    expect(memberCharges($manager, $member)[0]->amount)->toBe(40_000)
        ->and(memberCharges($manager, $member)[0]->tax_amount)->toBe(0)
        ->and(memberCharges($other, enrolledDraft($other, ['entry_fee_amount' => 40_000]))[0]->amount)->toBe(47_200);
});
