<?php

use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;

/*
 * Catalogue vu du centre (06-IMPLEMENTATION-PLAN étape 4.2, F-OBL-01, RG-OBL-01 à 03) : liste,
 * obligations applicables à un futur dossier (assistant d'inscription), obligations locales créées et
 * modifiées par le centre, jamais le catalogue national ni celles d'un autre CGA.
 */

/**
 * @return array<string, mixed>
 */
function localObligationForm(array $overrides = []): array
{
    return [
        'code' => 'LOC-1', 'label' => 'Taxe communale d\'essai', 'category' => 'other', 'authority' => 'Commune',
        'kind' => 'payment', 'periodicity' => 'yearly', 'due_rule' => ['type' => 'fixed_date', 'month' => 3, 'day' => 31],
        'applicability' => ['regimes' => [], 'min_employees' => null, 'sectors' => []], ...$overrides,
    ];
}

it('F-OBL-01 · liste le catalogue national en vigueur et toutes les obligations locales du centre', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    ObligationType::factory()->create(['label' => 'B · Nationale']);
    ObligationType::factory()->create(['label' => 'Nationale retirée', 'active' => false]);
    ObligationType::factory()->localTo($manager->organization)->create(['label' => 'A · Locale inactive', 'active' => false]);
    ObligationType::factory()->localTo(Organization::factory()->create())->create(['label' => 'Locale d\'un autre CGA']);

    inSpace($manager)->getJson('/api/v1/cga/obligation-types')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.label', 'A · Locale inactive')
        ->assertJsonPath('data.0.local', true)
        ->assertJsonPath('data.0.can.update', true)
        ->assertJsonPath('data.1.label', 'B · Nationale')
        ->assertJsonPath('data.1.can.update', false)
        ->assertJsonPath('data.1.category_label', 'Impôts et taxes')
        ->assertJsonPath('data.1.due_rule.day', 15);
});

it('RG-OBL-02, 03 · obligations applicables à un futur dossier : régime, effectif, secteur', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $transport = Sector::factory()->create(['code' => 'TRA001']);
    ObligationType::factory()->applicableTo(['regimes' => ['RME']])->create(['label' => 'A · RME']);
    ObligationType::factory()->applicableTo(['regimes' => ['TEE']])->create(['label' => 'B · TEE']);
    ObligationType::factory()->create(['label' => 'C · CNPS', 'category' => 'cnps']);
    ObligationType::factory()->applicableTo(['sectors' => ['TRA001']])->create(['label' => 'D · Transport']);

    inSpace($manager)->getJson('/api/v1/cga/obligation-types/suggestions?regime=TEE')
        ->assertOk()->assertJsonPath('data.*.label', ['B · TEE']);
    inSpace($manager)->getJson("/api/v1/cga/obligation-types/suggestions?regime=RME&employees_count=4&sector_id={$transport->id}")
        // Triées par catégorie (CNPS avant impôts), puis par libellé.
        ->assertOk()->assertJsonPath('data.*.label', ['C · CNPS', 'A · RME', 'D · Transport']);
    inSpace($manager)->getJson('/api/v1/cga/obligation-types/suggestions?regime=XYZ&employees_count=-1')
        ->assertUnprocessable()->assertJsonValidationErrors(['regime', 'employees_count']);
});

it('RG-OBL-01 · crée et modifie une obligation locale : validée, version locale, code unique dans le centre', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();

    $id = inSpace($manager)->postJson('/api/v1/cga/obligation-types', localObligationForm())
        ->assertCreated()
        ->assertJsonPath('data.local', true)
        ->assertJsonPath('data.catalog_version', 'local')
        ->assertJsonPath('data.data_status', 'validated')
        ->assertJsonPath('data.creates_payment_on_done', true)
        ->json('data.id');
    inSpace($manager)->postJson('/api/v1/cga/obligation-types', localObligationForm())
        ->assertUnprocessable()->assertJsonValidationErrors('code');

    inSpace($manager)->putJson("/api/v1/cga/obligation-types/{$id}", localObligationForm(['label' => 'Taxe communale', 'active' => false, 'tax_nature' => 'other']))
        ->assertOk()
        ->assertJsonPath('data.label', 'Taxe communale')
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('data.tax_nature', 'other');

    // Même code dans un autre centre : permis.
    inSpace(Membership::factory()->role(Role::CgaManager)->create())->postJson('/api/v1/cga/obligation-types', localObligationForm())->assertCreated();
});

it('contrôle la saisie d\'une obligation locale (422) : règle d\'échéance, catégorie, secteur inconnu', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();

    inSpace($manager)->postJson('/api/v1/cga/obligation-types', localObligationForm([
        'category' => 'impots', 'due_rule' => ['type' => 'fixed_date', 'month' => 13],
        'applicability' => ['regimes' => ['XYZ'], 'min_employees' => -2, 'sectors' => ['INCONNU']],
    ]))->assertUnprocessable()->assertJsonValidationErrors([
        'category', 'due_rule.month', 'due_rule.day', 'applicability.regimes.0', 'applicability.min_employees', 'applicability.sectors.0',
    ]);
});

it('ne modifie jamais le catalogue national ni l\'obligation d\'un autre CGA ; réserve la gestion aux droits « Gérer les obligations »', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $national = ObligationType::factory()->create();
    $foreign = ObligationType::factory()->localTo(Organization::factory()->create())->create();
    $trainer = Membership::factory()->role(Role::CgaTrainer)->create(['organization_id' => $manager->organization_id]);
    $accountant = Membership::factory()->role(Role::CgaAccountant)->create(['organization_id' => $manager->organization_id]);

    inSpace($manager)->putJson("/api/v1/cga/obligation-types/{$national->id}", localObligationForm())->assertForbidden();
    inSpace($manager)->putJson("/api/v1/cga/obligation-types/{$foreign->id}", localObligationForm())->assertForbidden();
    inSpace($trainer)->getJson('/api/v1/cga/obligation-types')->assertOk();
    inSpace($trainer)->postJson('/api/v1/cga/obligation-types', localObligationForm())->assertForbidden();
    inSpace($accountant)->postJson('/api/v1/cga/obligation-types', localObligationForm())->assertCreated();

    expect($national->refresh()->label)->not->toBe('Taxe communale d\'essai');
});
