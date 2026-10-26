<?php

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\UserPermissionOverride;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Portefeuilles CGA et Cabinet (06-IMPLEMENTATION-PLAN étape 2.12, RG-PTF-01 à 08, RG-SEC-08) — test
 * bloquant : un dossier Cabinet n'apparaît dans aucune sortie réglementaire ; aucun dossier CGA sans
 * contrat ; aucun apporteur sur un dossier Cabinet ; l'apporteur n'apparaît dans aucune réponse sans
 * le droit, ni dans aucun document officiel.
 */

function portfolioAgent(Role $role, string $organizationId): Membership
{
    return Membership::factory()->role($role)->create(['organization_id' => $organizationId]);
}

/**
 * Retire un droit à un agent (habilitation par utilisateur, RG-ROLE-07).
 */
function withoutPermission(Membership $agent, string $permission): Membership
{
    app(TenantContext::class)->runAs($agent, function () use ($agent, $permission): void {
        $override = new UserPermissionOverride;
        $override->membership_id = $agent->id;
        $override->permission = $permission;
        $override->granted = false;
        $override->updated_by = $agent->user_id;
        $override->save();
    });

    return $agent;
}

it('RG-PTF-02 RG-SEC-08 ne compte jamais le portefeuille Cabinet dans les sorties réglementaires', function () {
    $cga = Organization::factory()->create();
    Member::factory()->active()->create(['organization_id' => $cga->id]);
    Member::factory()->active()->create(['organization_id' => $cga->id, 'status' => MemberStatus::RadiationPending]);
    Member::factory()->active()->cabinet()->create(['organization_id' => $cga->id, 'membership_number' => null, 'client_number' => 'CAB-TST-00001']);
    Member::factory()->create(['organization_id' => $cga->id]);
    Member::factory()->active()->create(['organization_id' => $cga->id, 'status' => MemberStatus::Radiated]);

    expect(Tenancy::bypass(fn () => Member::query()->regulatory()->where('organization_id', $cga->id)->count(), 'Test du portefeuille réglementaire.'))->toBe(4);
    inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson('/api/v1/network/organizations')
        ->assertOk()
        ->assertJsonPath('data.0.id', $cga->id)
        ->assertJsonPath('data.0.members_count', 2);
    inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson("/api/v1/network/organizations/{$cga->id}")->assertJsonPath('data.members_count', 2);
});

it('RG-PTF-08 refuse en base un dossier du portefeuille CGA validé sans date de signature du contrat', function () {
    expect(fn () => DB::transaction(fn () => Member::factory()->active()->create(['cga_contract_signed_on' => null])))->toThrow(QueryException::class, 'members_cga_requires_contract');

    $draft = Member::factory()->create(['cga_contract_signed_on' => null]);
    expect($draft->status)->toBe(MemberStatus::Draft);
    $cabinet = Member::factory()->active()->cabinet()->create(['cga_contract_signed_on' => null, 'membership_number' => null]);
    expect($cabinet->portfolio->value)->toBe('cabinet');
});

it('RG-PTF-06 refuse en base et à la saisie un apporteur sur un dossier du portefeuille Cabinet', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $referrer = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id]);

    expect(fn () => DB::transaction(fn () => Member::factory()->cabinet()->create(['organization_id' => $manager->organization_id, 'referrer_id' => $referrer->id])))->toThrow(QueryException::class, 'members_referrer_cga_only');
    inSpace($manager)->postJson('/api/v1/cga/members', [
        'portfolio' => 'cabinet', 'entity_type' => 'company', 'company_name' => 'Client du cabinet', 'legal_form' => 'SARL', 'ncc' => '1111111C',
        'regime' => 'RSI', 'main_activity' => 'Commerce', 'phone' => '0707070707', 'referrer_id' => $referrer->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('referrer_id');
});

it('RG-PTF-06 ne montre l\'apporteur dans aucune réponse sans le droit « Voir les apporteurs »', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $referrer = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id, 'label' => 'Accessoire 7', 'name' => 'Ibrahim Apporteur']);
    $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'referrer_id' => $referrer->id]);
    $accountant = portfolioAgent(Role::CgaAccountant, $manager->organization_id);
    $expert = withoutPermission(portfolioAgent(Role::CgaAccountantExpert, $manager->organization_id), 'members.referrers.view');

    foreach ([$accountant, $expert] as $agent) {
        foreach (["/api/v1/cga/members/{$member->id}", '/api/v1/cga/members', "/api/v1/cga/members/{$member->id}/regimes", "/api/v1/cga/members/{$member->id}/pieces"] as $uri) {
            $content = (string) inSpace($agent)->getJson($uri)->assertOk()->getContent();
            expect($content)->not->toContain('Accessoire 7')->not->toContain('Ibrahim Apporteur')->not->toContain($referrer->id)->not->toContain('"referrer"');
        }
        inSpace($agent)->getJson("/api/v1/cga/members?filter[referrer_id]={$referrer->id}")->assertUnprocessable()->assertJsonValidationErrors('filter.referrer_id');
    }

    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}")->assertJsonPath('data.referrer.label', 'Accessoire 7');
    inSpace($manager)->getJson("/api/v1/cga/members?filter[referrer_id]={$referrer->id}")->assertOk()->assertJsonCount(1, 'data');
});

it('RG-PTF-06 n\'injecte jamais l\'apporteur dans un document officiel', function () {
    foreach (config('documents.templates') as $type => $template) {
        $variables = [...($template['variables'] ?? []), ...array_merge([], ...array_values($template['rows'] ?? []))];
        expect(implode(' ', $variables))->not->toMatch('/referrer|apporteur|accessoire/i', "Variable d'apporteur dans le modèle {$type}");

        foreach (templateParts($template['path']) as $part => $xml) {
            expect(mb_strtolower(strip_tags($xml)))->not->toContain('apporteur')->not->toContain('accessoire');
        }
    }
});

it('RG-PTF-02 réserve le portefeuille Cabinet au droit de le voir', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $cabinet = Member::factory()->active()->cabinet()->create(['organization_id' => $manager->organization_id, 'membership_number' => null]);
    $agent = withoutPermission(portfolioAgent(Role::CgaAccountant, $manager->organization_id), 'members.cabinet.view');

    inSpace($agent)->getJson("/api/v1/cga/members/{$cabinet->id}")->assertForbidden();
    inSpace($agent)->getJson('/api/v1/cga/members?filter[portfolio]=cabinet')->assertForbidden();
    inSpace($agent)->getJson('/api/v1/cga/members?filter[portfolio]=all')->assertForbidden();
    inSpace($agent)->getJson('/api/v1/cga/members')->assertOk()->assertJsonCount(0, 'data');
    inSpace($manager)->getJson('/api/v1/cga/members?filter[portfolio]=cabinet')->assertOk()->assertJsonCount(1, 'data');
    inSpace($manager)->getJson('/api/v1/cga/members?filter[portfolio]=all')->assertOk()->assertJsonCount(1, 'data');
});
