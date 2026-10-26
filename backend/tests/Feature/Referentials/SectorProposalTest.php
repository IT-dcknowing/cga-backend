<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;

/*
 * « Proposer un secteur » depuis l'assistant d'inscription (06-IMPLEMENTATION-PLAN étape 2.8,
 * RG-REF-01) : POST /api/v1/cga/sectors, code attribué par le serveur, secteur aussitôt utilisable par
 * le CGA qui le propose, invisible des autres CGA.
 */

function sectorAgent(Role $role = Role::CgaManager, array $organization = []): Membership
{
    $agent = Membership::factory()->role($role)->create();
    $agent->organization->forceFill(['numbering_abbreviation' => 'DCK', ...$organization])->save();

    return $agent;
}

it('RG-REF-01 enregistre le secteur proposé, au code du centre, utilisable aussitôt par lui seul', function () {
    $agent = sectorAgent();

    $first = inSpace($agent)->postJson('/api/v1/cga/sectors', ['name' => '  Transformation du cacao  '])
        ->assertCreated()
        ->assertJsonPath('data.code', 'DCK0001')
        ->assertJsonPath('data.name', 'Transformation du cacao')
        ->assertJsonPath('data.status', 'proposed');
    inSpace($agent)->postJson('/api/v1/cga/sectors', ['name' => 'Pêche artisanale'])->assertJsonPath('data.code', 'DCK0002');

    $sector = Sector::query()->findOrFail($first->json('data.id'));
    expect($sector->proposed_by_organization_id)->toBe($agent->organization_id);

    // Visible et utilisable dans le centre (inscription d'un adhérent) ; invisible ailleurs.
    inSpace($agent)->getJson('/api/v1/referentials/sectors?filter[search]=cacao')->assertJsonCount(1, 'data');
    inSpace($agent)->postJson('/api/v1/cga/members', [
        'portfolio' => 'cga', 'entity_type' => 'company', 'company_name' => 'Cacao Plus', 'legal_form' => 'SARL',
        'ncc' => '1864699A', 'regime' => 'TEE', 'main_activity' => 'Broyage', 'phone' => '0707070707', 'sector_id' => $sector->id,
    ])->assertCreated()->assertJsonPath('data.sector.status', 'proposed');
    $other = sectorAgent(organization: ['numbering_abbreviation' => 'ABJ']);
    inSpace($other)->getJson('/api/v1/referentials/sectors?filter[search]=cacao')->assertJsonCount(0, 'data');
    inSpace($other)->getJson("/api/v1/referentials/sectors/{$sector->id}")->assertNotFound();

    expect(ActivityLog::query()->where('action', 'sectors.propose')->count())->toBe(2);
});

it('suit le format paramétré du code (numbering.sector_proposal.pattern), sans dépasser 10 caractères', function () {
    app(SettingsService::class)->set('numbering.sector_proposal.pattern', 'SP-{ABR}{N:2}');
    $agent = sectorAgent();

    inSpace($agent)->postJson('/api/v1/cga/sectors', ['name' => 'Orpaillage'])->assertCreated()->assertJsonPath('data.code', 'SP-DCK01');

    app(SettingsService::class)->set('numbering.sector_proposal.pattern', 'SECT-{ABR}-{N:4}');
    inSpace($agent)->postJson('/api/v1/cga/sectors', ['name' => 'Pisciculture'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'sector_code_too_long');
    expect(Sector::query()->where('name', 'Pisciculture')->exists())->toBeFalse();
});

it('valide le nom : obligatoire, 120 caractères au plus, pas déjà pris par un secteur visible du centre', function () {
    $agent = sectorAgent();
    Sector::factory()->create(['name' => 'Commerce général']);
    $hidden = Sector::factory()->proposedBy(Membership::factory()->role(Role::CgaManager)->create()->organization)->create(['name' => 'Proposé ailleurs']);

    inSpace($agent)->postJson('/api/v1/cga/sectors', [])->assertUnprocessable()->assertJsonValidationErrors('name');
    inSpace($agent)->postJson('/api/v1/cga/sectors', ['name' => str_repeat('a', 121)])->assertJsonValidationErrors('name');
    inSpace($agent)->postJson('/api/v1/cga/sectors', ['name' => 'COMMERCE GÉNÉRAL'])->assertJsonValidationErrors('name');
    // Le joker d'une recherche LIKE reste un caractère ordinaire.
    inSpace($agent)->postJson('/api/v1/cga/sectors', ['name' => 'Commerce%'])->assertCreated();
    // Un nom proposé par un autre CGA ne se devine pas par un refus.
    inSpace($agent)->postJson('/api/v1/cga/sectors', ['name' => $hidden->name])->assertCreated();
});

it('fixe code, statut et auteur côté serveur, quelle que soit la saisie', function () {
    $agent = sectorAgent();

    inSpace($agent)->postJson('/api/v1/cga/sectors', ['name' => 'Élevage', 'code' => 'HACK', 'status' => 'validated', 'proposed_by_organization_id' => 'x'])
        ->assertCreated()
        ->assertJsonPath('data.code', 'DCK0001')
        ->assertJsonPath('data.status', 'proposed');
});

it('refuse sans abréviation de numérotation du centre (409 abbreviation_missing)', function () {
    $agent = sectorAgent(organization: ['numbering_abbreviation' => null]);

    inSpace($agent)->postJson('/api/v1/cga/sectors', ['name' => 'Élevage'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'abbreviation_missing');
    expect(Sector::query()->where('name', 'Élevage')->exists())->toBeFalse();
});

it('réserve la proposition aux agents d\'un CGA qui inscrivent les adhérents', function (Role $role, int $status) {
    inSpace(sectorAgent($role))->postJson('/api/v1/cga/sectors', ['name' => 'Élevage'])->assertStatus($status);
})->with([
    'gérant' => [Role::CgaManager, 201],
    'secrétaire' => [Role::CgaSecretary, 201],
    'chargé de facturation' => [Role::CgaBillingOfficer, 403],
    'formateur' => [Role::CgaTrainer, 403],
    'Présidente du CNCGA' => [Role::CncgaPresident, 403],
]);

it('refuse un visiteur sans session', function () {
    $this->postJson('/api/v1/cga/sectors', ['name' => 'Élevage'])->assertUnauthorized();
});
