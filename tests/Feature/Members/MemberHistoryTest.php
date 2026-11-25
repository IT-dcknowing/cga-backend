<?php

use App\Domain\Auth\Support\AuthSession;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Spa;

/*
 * Onglet « Historique » du dossier (06-IMPLEMENTATION-PLAN étape 2.9, F-ADH-03) :
 * GET /api/v1/cga/members/{member}/history — journal du dossier et de ses éléments, du plus récent au
 * plus ancien, filtré selon les droits (RG-PTF-06, observation d'une radiation).
 */

beforeEach(function () {
    Storage::fake();
});

function historyManager(): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create([
        'organization_id' => Organization::factory()->create(['numbering_abbreviation' => 'HIS', 'abbreviation_locked_at' => now()])->id,
    ]);
}

function historyAgent(Membership $manager, Role $role): Membership
{
    return Membership::factory()->role($role)->create(['organization_id' => $manager->organization_id]);
}

/**
 * @return list<array<string, mixed>>
 */
function historyOf(Membership $agent, Member $member): array
{
    return (array) inSpace($agent)->getJson("/api/v1/cga/members/{$member->id}/history")->assertOk()->json('data');
}

/**
 * @param  list<array<string, mixed>>  $entries
 * @return array<string, mixed>|null
 */
function historyChange(array $entries, string $field): ?array
{
    foreach ($entries as $entry) {
        foreach ($entry['changes'] as $change) {
            if ($change['field'] === $field) {
                return $change;
            }
        }
    }

    return null;
}

it('montre les modifications du dossier, du plus récent au plus ancien : auteur, avant / après, justificatif, référentiels nommés', function () {
    $manager = historyManager();
    $office = TaxOffice::factory()->create(['name' => 'CDI Historique']);
    $member = validatedMember($manager, ['company_name' => 'ANCIEN NOM', 'ncc' => '1864699A']);

    inSpace($manager)->post("/api/v1/cga/members/{$member->id}", [
        '_method' => 'PUT', 'entity_type' => 'company', 'company_name' => 'Nouveau Nom', 'legal_form' => $member->legal_form->value,
        'ncc' => '1864699A', 'regime' => $member->regime->value, 'main_activity' => $member->main_activity, 'phone' => $member->phone,
        'tax_office_id' => $office->id, 'justification' => UploadedFile::fake()->createWithContent('kbis.pdf', "%PDF-1.4\n%%EOF"),
    ], ['Accept' => 'application/json'])->assertOk();

    $entries = historyOf($manager, $member);
    $update = $entries[0];

    expect($update['event'])->toBe('updated')
        ->and($update['event_label'])->toBe('Modification')
        ->and($update['subject'])->toBe('member')
        ->and($update['actor'])->toBe($manager->user->fullName())
        ->and($update['justification']['name'])->toBe('kbis.pdf')
        ->and($update['justification']['url'])->toBeString()
        ->and(historyChange([$update], 'company_name'))->toMatchArray(['label' => 'Raison sociale', 'before' => 'ANCIEN NOM', 'after' => 'NOUVEAU NOM'])
        ->and(historyChange([$update], 'tax_office_id')['after'])->toBe('CDI Historique')
        // Champs techniques jamais montrés.
        ->and(historyChange($entries, 'search_text'))->toBeNull()
        ->and(historyChange($entries, 'organization_id'))->toBeNull()
        ->and(end($entries)['event'])->toBe('created');
});

it('reprend les éléments du dossier : chiffre d\'affaires et sa source, radiation demandée', function () {
    $manager = historyManager();
    $member = validatedMember($manager);

    inSpace($manager)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()])->post("/api/v1/cga/members/{$member->id}/radiation", [
        'initiator' => 'cga', 'reason' => 'reclassified_real_regime', 'effective_date' => today()->toDateString(),
        'observation' => 'Observation confidentielle.',
    ], ['Accept' => 'application/json'])->assertCreated();
    app(TenantContext::class)->runAs($manager, fn () => (new MemberRevenue)->forceFill([
        'organization_id' => $member->organization_id, 'member_id' => $member->id, 'year' => 2025, 'amount' => 9000000, 'kind' => 'actual', 'source' => 'compta_flow',
    ])->save());

    $entries = historyOf($manager, $member);
    $subjects = array_column($entries, 'subject');

    expect($subjects)->toContain('radiation', 'revenue')
        ->and(collect($entries)->firstWhere('event', 'radiation_requested')['event_label'])->toBe('Radiation demandée')
        ->and(collect($entries)->firstWhere('subject', 'revenue')['source_label'])->toBe('Compta Flow')
        // Chiffrée en base : annoncée, jamais montrée en clair ni chiffrée.
        ->and(historyChange($entries, 'observation')['after'])->toBe('Donnée chiffrée : consultable sur la fiche')
        ->and(json_encode($entries))->not->toContain('Observation confidentielle')->not->toContain('eyJpdi');

    // Le comptable ne demande ni ne valide les radiations : l'observation lui reste cachée.
    $accountant = historyAgent($manager, Role::CgaAccountant);
    expect(historyChange(historyOf($accountant, $member), 'observation'))->toBeNull()
        ->and(historyChange(historyOf($accountant, $member), 'reason'))->not->toBeNull();
});

it('RG-PTF-06 ne montre l\'apporteur qu\'avec le droit « Voir les apporteurs »', function () {
    $manager = historyManager();
    $referrer = app(TenantContext::class)->runAs($manager, fn () => BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id, 'label' => 'Accessoire 7']));
    $member = validatedMember($manager);
    app(TenantContext::class)->runAs($manager, fn () => $member->forceFill(['referrer_id' => $referrer->id])->save());

    expect(historyChange(historyOf($manager, $member), 'referrer_id')['after'])->toBe('Accessoire 7')
        ->and(historyChange(historyOf(historyAgent($manager, Role::CgaAccountant), $member), 'referrer_id'))->toBeNull();
});

it('pagine l\'historique et borne per_page', function () {
    $manager = historyManager();
    $member = validatedMember($manager);
    foreach (['A', 'B', 'C'] as $name) {
        app(TenantContext::class)->runAs($manager, fn () => $member->forceFill(['company_name' => "NOM {$name}"])->save());
    }

    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}/history?per_page=2")
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4)->assertJsonPath('meta.last_page', 2);
    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}/history?per_page=1000")->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('applique les droits du dossier : centre, portefeuille Cabinet, espace', function (Role $role, int $status) {
    $manager = historyManager();
    $member = validatedMember($manager);

    inSpace(historyAgent($manager, $role))->getJson("/api/v1/cga/members/{$member->id}/history")->assertStatus($status);
})->with([
    'gérant' => [Role::CgaManager, 200],
    'secrétaire' => [Role::CgaSecretary, 200],
    'formateur' => [Role::CgaTrainer, 200],
]);

it('refuse un dossier d\'un autre CGA, le Cabinet sans droit, et hors d\'un CGA', function () {
    $manager = historyManager();
    $elsewhere = Member::factory()->active()->create();
    $cabinet = Member::factory()->active()->cabinet()->create(['organization_id' => $manager->organization_id, 'membership_number' => null]);

    inSpace($manager)->getJson("/api/v1/cga/members/{$elsewhere->id}/history")->assertNotFound();
    inSpace(historyAgent($manager, Role::CgaTrainer))->getJson("/api/v1/cga/members/{$cabinet->id}/history")->assertForbidden();
    inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson("/api/v1/cga/members/{$elsewhere->id}/history")->assertNotFound();
});

it('refuse un visiteur sans session', function () {
    $member = Member::factory()->active()->create();

    $this->withHeaders(Spa::headers())->getJson("/api/v1/cga/members/{$member->id}/history")->assertUnauthorized();
});
