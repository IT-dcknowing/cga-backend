<?php

use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Models\CgaPerson;
use App\Domain\Reports\Models\CgaResource;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Fiche permanente du CGA (06-IMPLEMENTATION-PLAN étape 5.1, F-RPT-01) : présentation, dirigeants et
 * personnel, moyens matériels par catégorie ; tenue par le gérant et l'expert-comptable, lue par qui
 * prépare le rapport ; cloisonnée.
 */

beforeEach(function () {
    $this->travelTo('2026-11-02 10:00:00');
});

function profileManager(): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create();
}

/**
 * @return array<string, mixed>
 */
function personPayload(array $overrides = []): array
{
    return [
        'kind' => 'director',
        'full_name' => 'Awa Koné',
        'nationality' => 'Ivoirienne',
        'profession' => 'Expert-comptable',
        'diplomas' => 'DECOFI',
        'function_in_cga' => 'Gérante',
        'start_date' => '2019-03-01',
        'practice_structure' => 'Cabinet fictif',
        'practice_function' => 'Associée',
        'practice_years' => 14,
        'cnps_declared' => true,
        'active' => true,
        'end_date' => null,
        ...$overrides,
    ];
}

it('montre la fiche : présentation tirée de la fiche du centre, personnes, moyens de chaque catégorie', function () {
    $manager = profileManager();
    Tenancy::bypass(static fn () => $manager->organization->forceFill(['history_text' => 'Créé en 2004.', 'previous_year_revenue' => 48_000_000, 'said' => 'SAID Plateau'])->save(), 'Test.');
    CgaPerson::factory()->create(['organization_id' => $manager->organization_id, 'full_name' => 'Awa Koné']);
    CgaResource::factory()->create(['organization_id' => $manager->organization_id]);
    CgaPerson::factory()->create();

    inSpace($manager)->getJson('/api/v1/cga/permanent-profile')
        ->assertOk()
        ->assertJsonPath('data.presentation.history_text', 'Créé en 2004.')
        ->assertJsonPath('data.presentation.previous_year_revenue', 48_000_000)
        ->assertJsonPath('data.presentation.said', 'SAID Plateau')
        ->assertJsonCount(1, 'data.people')
        ->assertJsonPath('data.people.0.full_name', 'Awa Koné')
        ->assertJsonCount(8, 'data.resources')
        ->assertJsonPath('data.resources.0', ['category' => 'premises', 'category_label' => 'Locaux', 'entry' => null])
        ->assertJsonPath('data.resources.3.entry.quantity', 6)
        ->assertJsonPath('data.can.manage', true);
});

it('ajoute et met à jour une personne ; un départ exige sa date de fin', function () {
    $manager = profileManager();

    $id = inSpace($manager)->postJson('/api/v1/cga/permanent-profile/people', personPayload())
        ->assertCreated()->assertJsonPath('data.kind_label', 'Dirigeant')->json('data.id');
    inSpace($manager)->putJson("/api/v1/cga/permanent-profile/people/{$id}", personPayload(['active' => false]))
        ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    inSpace($manager)->putJson("/api/v1/cga/permanent-profile/people/{$id}", personPayload(['active' => false, 'end_date' => '2026-09-30']))
        ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.end_date', '2026-09-30');
    inSpace($manager)->putJson("/api/v1/cga/permanent-profile/people/{$id}", personPayload(['active' => false, 'end_date' => '2018-01-01']))
        ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    inSpace($manager)->postJson('/api/v1/cga/permanent-profile/people', personPayload(['kind' => 'stagiaire', 'full_name' => '', 'practice_years' => -1]))
        ->assertUnprocessable()->assertJsonValidationErrors(['kind', 'full_name', 'practice_years']);

    $this->assertDatabaseHas('audit_logs', ['subject_id' => $id, 'event' => 'updated']);
});

it('enregistre les moyens d\'une catégorie, une ligne par catégorie', function () {
    $manager = profileManager();

    inSpace($manager)->putJson('/api/v1/cga/permanent-profile/resources/vehicles', ['quantity' => 2, 'description' => 'Deux motos', 'need' => 'Un véhicule'])
        ->assertCreated()->assertJsonPath('data.category', 'vehicles')->assertJsonPath('data.quantity', 2);
    inSpace($manager)->putJson('/api/v1/cga/permanent-profile/resources/vehicles', ['quantity' => 3, 'description' => '', 'need' => null])
        ->assertOk()->assertJsonPath('data.quantity', 3)->assertJsonPath('data.description', null);
    inSpace($manager)->putJson('/api/v1/cga/permanent-profile/resources/vehicles', ['quantity' => -1])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    inSpace($manager)->putJson('/api/v1/cga/permanent-profile/resources/avions', ['quantity' => 1])->assertNotFound();

    expect(Tenancy::bypass(static fn () => CgaResource::query()->count(), 'Test.'))->toBe(1)
        ->and(fn () => DB::transaction(fn () => CgaResource::factory()->create(['organization_id' => $manager->organization_id, 'category' => 'vehicles'])))->toThrow(QueryException::class);
});

it('met à jour la présentation du centre sans toucher à son identité', function () {
    $manager = profileManager();
    $name = $manager->organization->name;

    inSpace($manager)->putJson('/api/v1/cga/permanent-profile/presentation', ['history_text' => ' Centre agréé en 2004. ', 'previous_year_revenue' => 52_000_000])
        ->assertOk()->assertJsonPath('data.history_text', 'Centre agréé en 2004.')->assertJsonPath('data.previous_year_revenue', 52_000_000)->assertJsonPath('data.name', $name);
    inSpace($manager)->putJson('/api/v1/cga/permanent-profile/presentation', ['history_text' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('previous_year_revenue');
    inSpace($manager)->putJson('/api/v1/cga/permanent-profile/presentation', ['history_text' => null, 'previous_year_revenue' => -5, 'name' => 'Autre'])
        ->assertUnprocessable()->assertJsonValidationErrors('previous_year_revenue');

    expect(Tenancy::bypass(static fn () => Organization::query()->findOrFail($manager->organization_id)->name, 'Test.'))->toBe($name);
});

it('tenue par internal.manage, lue aussi avec reports.prepare', function (Role $role, int $read, int $write) {
    $manager = profileManager();
    $agent = Membership::factory()->role($role)->create(['organization_id' => $manager->organization_id]);

    inSpace($agent)->getJson('/api/v1/cga/permanent-profile')->assertStatus($read);
    inSpace($agent)->postJson('/api/v1/cga/permanent-profile/people', personPayload())->assertStatus($write);
    inSpace($agent)->putJson('/api/v1/cga/permanent-profile/resources/computers', ['quantity' => 1])->assertStatus($write);
})->with([
    'gérant' => [Role::CgaManager, 200, 201],
    'expert-comptable' => [Role::CgaAccountantExpert, 200, 201],
    'comptable' => [Role::CgaAccountant, 200, 403],
    'secrétaire' => [Role::CgaSecretary, 200, 403],
    'chargé des honoraires' => [Role::CgaBillingOfficer, 403, 403],
    'formateur' => [Role::CgaTrainer, 403, 403],
]);

it('RG-SEC-02 · la personne d\'un autre CGA répond 404 ; ni le CNCGA ni l\'adhérent n\'y accèdent', function () {
    $manager = profileManager();
    $foreign = CgaPerson::factory()->create();

    inSpace($manager)->putJson("/api/v1/cga/permanent-profile/people/{$foreign->id}", personPayload())->assertNotFound();
    inSpace(Membership::factory()->role(Role::CncgaManager)->create())->getJson('/api/v1/cga/permanent-profile')->assertForbidden();
    inSpace(Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $manager->organization_id]))->getJson('/api/v1/cga/permanent-profile')->assertForbidden();
});
