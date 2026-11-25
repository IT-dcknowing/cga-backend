<?php

use App\Domain\Auth\Http\Middleware\ExpireSessions;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * Espace actif chargé de la session (02-TRD §6.2) : sans espace utilisable, 409 space_required. Les
 * requêtes viennent du SPA (en-tête Origin) : Sanctum ouvre alors la session.
 */

beforeEach(function () {
    Route::middleware(['api', 'auth', 'tenant'])->get('api/v1/essai-espace', fn (TenantContext $context) => [
        'organization_id' => $context->organizationId(),
        'role' => $context->role()->value,
    ]);
});

function spaRequest(Membership $membership, ?string $activeMembershipId = null): TestResponse
{
    $test = test()->actingAs($membership->user)->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);

    if ($activeMembershipId !== null) {
        $test = $test->withSession([TenantContext::SESSION_KEY => $activeMembershipId]);
    }

    return $test->getJson('/api/v1/essai-espace');
}

it('charge l\'espace actif de la session', function () {
    $membership = Membership::factory()->create();

    spaRequest($membership, $membership->id)
        ->assertOk()
        ->assertExactJson(['organization_id' => $membership->organization_id, 'role' => 'cga_accountant']);
});

it('répond 409 space_required tant qu\'aucun espace n\'est choisi', function () {
    spaRequest(Membership::factory()->create())
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Choisissez d\'abord un espace de travail.', 'code' => 'space_required', 'details' => []]);
});

it('refuse l\'espace d\'un autre utilisateur', function () {
    $mine = Membership::factory()->create();
    $other = Membership::factory()->create();

    spaRequest($mine, $other->id)->assertStatus(409)->assertJsonPath('code', 'space_required');
});

it('refuse un rattachement désactivé ou encore invité, et l\'oublie', function (MembershipStatus $status) {
    $membership = Membership::factory()->create(['status' => $status]);

    spaRequest($membership, $membership->id)->assertStatus(409)->assertSessionMissing(TenantContext::SESSION_KEY);
})->with([MembershipStatus::Disabled, MembershipStatus::Invited]);

it('accepte un rattachement en consultation seule (adhérent radié, RG-ADH-12)', function () {
    $membership = Membership::factory()->create(['status' => MembershipStatus::ReadOnly]);

    spaRequest($membership, $membership->id)->assertOk();
});

it('refuse l\'espace d\'une organisation résiliée', function () {
    $membership = Membership::factory()->create();
    $membership->organization->status = OrganizationStatus::Terminated;
    $membership->organization->save();

    spaRequest($membership, $membership->id)->assertStatus(409);
});

it('ferme la session d\'un compte désactivé avant tout espace (02-TRD §6.1)', function () {
    $membership = Membership::factory()->create();
    $membership->user->status = UserStatus::Disabled;
    $membership->user->save();

    spaRequest($membership, $membership->id)->assertUnauthorized();
    $this->assertGuest();
});

it('refuse aussi l\'espace d\'un compte désactivé quand la session n\'a pas été fermée', function () {
    Route::middleware(['api', 'auth', 'tenant'])->withoutMiddleware(ExpireSessions::class)
        ->get('api/v1/essai-espace-seul', fn (TenantContext $context) => ['role' => $context->role()->value]);
    $membership = Membership::factory()->create();
    $membership->user->status = UserStatus::Disabled;
    $membership->user->save();

    test()->actingAs($membership->user)->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'])
        ->withSession([TenantContext::SESSION_KEY => $membership->id])
        ->getJson('/api/v1/essai-espace-seul')
        ->assertStatus(409)
        ->assertJsonPath('code', 'space_required');
});

it('exige une connexion', function () {
    $this->getJson('/api/v1/essai-espace')->assertUnauthorized();
});
