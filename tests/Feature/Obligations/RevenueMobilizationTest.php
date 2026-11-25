<?php

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;

/*
 * Mobilisation des recettes du portefeuille (onglet de /cga/echeances, 03-APPFLOW §4.3, étape 4.7,
 * RG-OBL-08) : montants dus, payés, restants et taux de recouvrement des échéances de paiement de la
 * période, par nature d'impôt et par contribuable ; montants à saisir comptés à part ; Cabinet avec
 * le droit de le voir seulement (RG-PTF-02). Jour de référence : 26/10/2026.
 */

beforeEach(function () {
    $this->travelTo('2026-10-26 10:00:00');
});

/**
 * Échéance de paiement d'un dossier du centre.
 *
 * @param  array<string, mixed>  $attributes
 */
function revenueLine(Member $member, array $attributes = []): ObligationInstance
{
    $type = ObligationType::factory()->create();
    $obligation = MemberObligation::factory()->create(['member_id' => $member->id, 'obligation_type_id' => $type->id]);

    return ObligationInstance::factory()->create([
        'member_obligation_id' => $obligation->id,
        'member_id' => $member->id,
        'organization_id' => $member->organization_id,
        'obligation_type_id' => $type->id,
        'label' => $type->label,
        'category' => ObligationCategory::Tax,
        'kind' => InstanceKind::Payment,
        'status' => InstanceStatus::ToPay,
        'due_date' => '2026-07-15',
        'amount_due' => null,
        'amount_paid' => 0,
        ...$attributes,
    ]);
}

/**
 * Centre d'essai : Alpha (TEE 30 000 dont 20 000 payés, CNPS 10 000 payés), Bêta (TEE sans montant
 * dû, une échéance annulée, une déclaration), Gamma au Cabinet (TVA 50 000), une échéance hors
 * période et une d'un autre centre.
 */
function revenueCentre(Organization $cga): void
{
    $alpha = Member::factory()->active()->create(['organization_id' => $cga->id, 'company_name' => 'Ets Alpha']);
    $beta = Member::factory()->active()->create(['organization_id' => $cga->id, 'company_name' => 'Ets Bêta']);
    $gamma = Member::factory()->active()->cabinet()->create(['organization_id' => $cga->id, 'company_name' => 'Cabinet Gamma', 'cga_contract_signed_on' => null]);

    revenueLine($alpha, ['tax_nature' => TaxNature::Tee, 'amount_due' => 30_000, 'amount_paid' => 20_000, 'status' => InstanceStatus::PartiallyPaid]);
    revenueLine($alpha, ['category' => ObligationCategory::Cnps, 'amount_due' => 10_000, 'amount_paid' => 10_000, 'status' => InstanceStatus::Paid, 'due_date' => '2026-09-15']);
    revenueLine($beta, ['tax_nature' => TaxNature::Tee, 'due_date' => '2026-08-15']);
    revenueLine($beta, ['tax_nature' => TaxNature::Tee, 'amount_due' => 99_999, 'status' => InstanceStatus::Cancelled]);
    revenueLine($beta, ['kind' => InstanceKind::Declaration, 'status' => InstanceStatus::Todo, 'amount_due' => 77_777]);
    revenueLine($beta, ['tax_nature' => TaxNature::Tee, 'amount_due' => 88_888, 'due_date' => '2027-01-15']);
    revenueLine($gamma, ['tax_nature' => TaxNature::Tva, 'amount_due' => 50_000, 'due_date' => '2026-06-30']);
    revenueLine(Member::factory()->active()->create(), ['tax_nature' => TaxNature::Tee, 'amount_due' => 66_666]);
}

it('RG-OBL-08 · par nature et par contribuable, du 1er janvier au jour : dû, payé, reste, taux ; montants à saisir à part', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    revenueCentre($manager->organization);

    $response = inSpace($manager)->getJson('/api/v1/cga/obligation-planning/revenue')->assertOk()
        ->assertJsonPath('data.from', '2026-01-01')
        ->assertJsonPath('data.to', '2026-10-26')
        ->assertJsonPath('data.members_total', 3);

    expect($response->json('data.totals'))->toEqual(['amount_due' => 90_000, 'amount_paid' => 30_000, 'balance' => 60_000, 'recovery_rate' => 33.3, 'amounts_to_enter' => 1])
        ->and(array_column($response->json('data.natures'), 'tax_nature'))->toBe(['tva', 'tee', 'cnps'])
        ->and($response->json('data.natures.1'))->toEqual([
            'tax_nature' => 'tee', 'tax_nature_label' => TaxNature::Tee->label(),
            'amount_due' => 30_000, 'amount_paid' => 20_000, 'balance' => 10_000, 'recovery_rate' => 66.7, 'amounts_to_enter' => 1,
        ])
        ->and($response->json('data.natures.2.recovery_rate'))->toEqual(100)
        ->and(array_column(array_column($response->json('data.members'), 'member'), 'company_name'))->toBe(['Cabinet Gamma', 'Ets Alpha', 'Ets Bêta'])
        ->and($response->json('data.members.2'))->toMatchArray(['amount_due' => 0, 'balance' => 0, 'recovery_rate' => null, 'amounts_to_enter' => 1]);
});

it('RG-PTF-02 · sans le droit de voir le Cabinet, ses dossiers ne comptent pas', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    revenueCentre($manager->organization);
    $trainer = Membership::factory()->role(Role::CgaTrainer)->create(['organization_id' => $manager->organization_id]);

    $response = inSpace($trainer)->getJson('/api/v1/cga/obligation-planning/revenue')->assertOk()->assertJsonPath('data.members_total', 2);

    expect($response->json('data.totals'))->toEqual(['amount_due' => 40_000, 'amount_paid' => 30_000, 'balance' => 10_000, 'recovery_rate' => 75, 'amounts_to_enter' => 1]);
});

it('période choisie ; liste des contribuables limitée par la configuration', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    revenueCentre($manager->organization);
    config(['cga.obligations.revenue_members_limit' => 1]);

    inSpace($manager)->getJson('/api/v1/cga/obligation-planning/revenue?from=2026-09-01&to=2026-12-31')->assertOk()
        ->assertJsonPath('data.totals.amount_due', 10_000)
        ->assertJsonPath('data.members_total', 1)
        ->assertJsonCount(1, 'data.natures');
    inSpace($manager)->getJson('/api/v1/cga/obligation-planning/revenue')->assertOk()
        ->assertJsonCount(1, 'data.members')
        ->assertJsonPath('data.members_total', 3);
});

it('valide la période ; refuse hors d\'un espace CGA', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id]);

    inSpace($manager)->getJson('/api/v1/cga/obligation-planning/revenue?from=2026-10-01&to=2026-09-01')
        ->assertUnprocessable()->assertJsonValidationErrors(['to']);
    inSpace($manager)->getJson('/api/v1/cga/obligation-planning/revenue?from=hier')
        ->assertUnprocessable()->assertJsonValidationErrors(['from']);
    inSpace(Membership::factory()->role(Role::CncgaManager)->create())->getJson('/api/v1/cga/obligation-planning/revenue')->assertForbidden();
    inSpace(Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $member->organization_id, 'member_id' => $member->id]))
        ->getJson('/api/v1/cga/obligation-planning/revenue')->assertForbidden();
});
