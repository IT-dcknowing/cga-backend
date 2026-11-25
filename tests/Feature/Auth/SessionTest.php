<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Models\UserPermissionOverride;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\Spa;

/*
 * Session du SPA (02-TRD §6.1, §6.2) : GET /me, choix de l'espace (F-AUTH-04), déconnexion, expiration
 * après 30 min d'inactivité pour un agent et 60 min pour un adhérent.
 */

function signIn(User $user): void
{
    test()->withHeaders(Spa::headers())
        ->postJson('/api/v1/auth/login', ['identifier' => $user->phone, 'password' => 'password'])
        ->assertOk();
}

function me(): TestResponse
{
    return test()->withHeaders(Spa::headers())->getJson('/api/v1/me');
}

function chooseSpace(string $membershipId): TestResponse
{
    return test()->withHeaders(Spa::headers())->postJson('/api/v1/me/space', ['membership_id' => $membershipId]);
}

it('répond 401 à GET /me sans session', function () {
    me()->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('répond 401 en JSON, sans redirection, même sans en-tête Accept', function () {
    $this->get('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('renvoie l\'utilisateur, ses espaces utilisables et les droits de l\'espace actif', function () {
    $user = User::factory()->create(['first_name' => 'Awa', 'last_name' => 'Koné', 'email' => 'awa@cga.ci']);
    $cga = Membership::factory()->role(Role::CgaManager)->create(['user_id' => $user->id]);
    Membership::factory()->role(Role::CncgaManager)->create(['user_id' => $user->id]);
    Membership::factory()->invited()->create(['user_id' => $user->id]);
    Membership::factory()->create(['user_id' => $user->id, 'status' => 'disabled']);
    signIn($user);
    chooseSpace($cga->id)->assertOk();

    $response = me()->assertOk()
        ->assertJsonPath('data.user', [
            'first_name' => 'Awa',
            'last_name' => 'Koné',
            'phone' => $user->phone,
            'email' => 'awa@cga.ci',
            'notification_preferences' => ['sms' => false, 'whatsapp' => false],
        ])
        ->assertJsonCount(2, 'data.memberships')
        ->assertJsonPath('data.active_membership_id', $cga->id)
        ->assertJsonPath('data.memberships.0.organization', [
            'id' => $cga->organization_id,
            'type' => 'cga',
            'name' => $cga->organization->name,
            'banking_enabled' => false,
        ])
        ->assertJsonPath('data.memberships.0.member', null)
        ->assertJsonPath('data.memberships.0.unread_notifications', 0);

    expect($response->json('data.permissions'))->toContain('members.view', 'cga_users.manage')
        ->not->toContain('organizations.suspend');
});

it('RG-ROLE-07 renvoie les droits effectifs, ajustements individuels compris', function () {
    $membership = Membership::factory()->role(Role::CgaAccountant)->create();
    app(TenantContext::class)->set($membership);
    $override = new UserPermissionOverride;
    $override->membership_id = $membership->id;
    $override->permission = 'members.view';
    $override->granted = false;
    $override->updated_by = User::factory()->create()->id;
    $override->save();
    app(TenantContext::class)->clear();
    signIn($membership->user);

    expect(me()->json('data.permissions'))->not->toContain('members.view');
});

it('F-AUTH-04 enregistre l\'espace choisi en session, le mémorise et le journalise', function () {
    $user = User::factory()->create();
    $cga = Membership::factory()->create(['user_id' => $user->id]);
    $cncga = Membership::factory()->role(Role::CncgaPresident)->create(['user_id' => $user->id]);
    signIn($user);

    $response = chooseSpace($cncga->id)->assertOk()
        ->assertJsonPath('data.active_membership_id', $cncga->id)
        ->assertJsonPath('data.last_membership_id', $cncga->id);

    expect($response->json('data.permissions'))->toContain('organizations.suspend')
        ->and(session(TenantContext::SESSION_KEY))->toBe($cncga->id)
        ->and($user->fresh()->last_space_membership_id)->toBe($cncga->id)
        ->and(ActivityLog::query()->where('request_id', $response->headers->get('X-Request-Id'))->sole())
        ->action->toBe('auth.space')
        ->subject_type->toBe(Membership::class)
        ->subject_id->toBe($cncga->id);

    chooseSpace($cga->id)->assertOk()->assertJsonPath('data.active_membership_id', $cga->id);
});

it('F-AUTH-04 ouvre les routes métier de l\'espace choisi', function () {
    Route::middleware(['api', 'auth', 'tenant'])->get('api/v1/essai-espace-choisi', fn (TenantContext $context) => ['organization_id' => $context->organizationId()]);
    $user = User::factory()->create();
    $first = Membership::factory()->create(['user_id' => $user->id]);
    Membership::factory()->create(['user_id' => $user->id]);
    signIn($user);

    $this->withHeaders(Spa::headers())->getJson('/api/v1/essai-espace-choisi')->assertStatus(409)->assertJsonPath('code', 'space_required');
    chooseSpace($first->id)->assertOk();
    $this->withHeaders(Spa::headers())->getJson('/api/v1/essai-espace-choisi')->assertOk()->assertJsonPath('organization_id', $first->organization_id);
});

it('F-AUTH-04 refuse un espace qui n\'est pas à l\'utilisateur ou n\'est plus utilisable (404)', function (string $case) {
    $membership = Membership::factory()->create();
    $target = match ($case) {
        'autre utilisateur' => Membership::factory()->create(),
        'désactivé' => Membership::factory()->create(['user_id' => $membership->user_id, 'status' => 'disabled']),
        'invité' => Membership::factory()->invited()->create(['user_id' => $membership->user_id]),
        'organisation résiliée' => Membership::factory()->create([
            'user_id' => $membership->user_id,
            'organization_id' => Organization::factory()->create(['status' => 'terminated']),
        ]),
    };
    signIn($membership->user);

    chooseSpace($target->id)->assertNotFound()->assertJsonPath('code', 'not_found');
    expect(session(TenantContext::SESSION_KEY))->toBe($membership->id);
})->with(['autre utilisateur', 'désactivé', 'invité', 'organisation résiliée']);

it('valide l\'espace demandé', function () {
    signIn(Membership::factory()->create()->user);

    chooseSpace('pas-un-ulid')->assertUnprocessable()->assertJsonValidationErrors('membership_id');
});

it('déconnecte, invalide la session et journalise l\'utilisateur', function () {
    $membership = Membership::factory()->create();
    signIn($membership->user);

    $response = $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest();
    me()->assertUnauthorized();
    expect(ActivityLog::query()->where('request_id', $response->headers->get('X-Request-Id'))->sole())
        ->action->toBe('auth.logout')
        ->user_id->toBe($membership->user_id);
});

it('refuse la déconnexion sans session', function () {
    $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/logout')->assertUnauthorized();
});

describe('déconnexion après inactivité (02-TRD §6.1)', function () {
    it('déconnecte un agent après 30 minutes sans requête', function () {
        signIn(Membership::factory()->create()->user);

        $this->travel(29)->minutes();
        me()->assertOk();
        $this->travel(29)->minutes();
        me()->assertOk();
        $this->travel(31)->minutes();
        me()->assertUnauthorized();
        $this->assertGuest();
    });

    it('déconnecte un adhérent après 60 minutes sans requête', function () {
        $membership = Membership::factory()->role(Role::MemberOwner)->create();
        signIn($membership->user);

        $this->travel(45)->minutes();
        me()->assertOk()->assertJsonPath('data.memberships.0.member.id', $membership->member_id);
        $this->travel(61)->minutes();
        me()->assertUnauthorized();
    });

    it('applique le délai réglé par l\'administrateur', function () {
        app(SettingsService::class)->set('session.timeout_agent_minutes', 10);
        signIn(Membership::factory()->create()->user);

        $this->travel(11)->minutes();
        me()->assertUnauthorized();
    });

    it('déconnecte un compte désactivé pendant sa session', function () {
        $membership = Membership::factory()->create();
        signIn($membership->user);
        $membership->user->forceFill(['status' => 'disabled'])->save();
        // Requête suivante : la garde relit le compte depuis la base, comme à chaque requête réelle.
        $this->app['auth']->forgetGuards();

        me()->assertUnauthorized();
    });

    it('laisse se reconnecter après l\'expiration', function () {
        $membership = Membership::factory()->create();
        signIn($membership->user);
        $this->travel(31)->minutes();

        signIn($membership->user);
        me()->assertOk();
    });
});
