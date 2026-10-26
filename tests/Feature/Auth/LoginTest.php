<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Auth\Actions\AttemptLogin;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Logging\AlertCounter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\Spa;

/*
 * F-AUTH-01 Connexion (02-TRD §6.1, 03-APPFLOW §2) : téléphone ou e-mail + mot de passe ; 5 échecs
 * en 15 minutes bloquent ; un compte sans rattachement actif ne se connecte pas.
 */

function attemptLogin(string $identifier, string $password = 'password', string $ip = '10.0.0.1'): TestResponse
{
    return test()->withHeaders(Spa::headers())
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['identifier' => $identifier, 'password' => $password]);
}

function accountOnPhone(Role $role = Role::CgaAccountant, array $user = []): Membership
{
    return Membership::factory()->role($role)->create(['user_id' => User::factory()->create(['phone' => '+2250700000001', ...$user])]);
}

it('F-AUTH-01 connecte par téléphone, quelle que soit la saisie du numéro', function (string $saisie) {
    $membership = accountOnPhone();

    attemptLogin($saisie)->assertOk()->assertJsonPath('data.user.phone', '+2250700000001');

    $this->assertAuthenticatedAs($membership->user);
})->with(['0700000001', '07 00 00 00 01', '+2250700000001', '2250700000001']);

it('F-AUTH-01 connecte par e-mail, sans tenir compte de la casse', function () {
    $membership = accountOnPhone(user: ['email' => 'awa.kone@cga.ci']);

    attemptLogin('Awa.Kone@CGA.ci')->assertOk();

    $this->assertAuthenticatedAs($membership->user);
});

it('F-AUTH-01 ouvre directement l\'espace d\'un utilisateur qui n\'en a qu\'un', function () {
    $membership = accountOnPhone(Role::CgaManager);

    $response = attemptLogin('0700000001')->assertOk()
        ->assertJsonPath('data.active_membership_id', $membership->id)
        ->assertJsonPath('data.last_membership_id', $membership->id)
        ->assertJsonPath('data.memberships.0.role', 'cga_manager')
        ->assertJsonPath('data.memberships.0.role_label', 'Gérant');

    expect($response->json('data.permissions'))->toContain('members.view')
        ->and(session(TenantContext::SESSION_KEY))->toBe($membership->id)
        ->and($membership->user->fresh()->last_login_at)->not->toBeNull()
        ->and($membership->user->fresh()->last_space_membership_id)->toBe($membership->id);
});

it('F-AUTH-01 / F-AUTH-04 laisse choisir l\'espace à un utilisateur qui en a plusieurs, présélectionné sur le dernier', function () {
    $first = accountOnPhone();
    $second = Membership::factory()->role(Role::CncgaManager)->create(['user_id' => $first->user_id]);
    $first->user->forceFill(['last_space_membership_id' => $second->id])->save();

    attemptLogin('0700000001')->assertOk()
        ->assertJsonPath('data.active_membership_id', null)
        ->assertJsonPath('data.last_membership_id', $second->id)
        ->assertJsonPath('data.permissions', [])
        ->assertJsonCount(2, 'data.memberships');
});

it('A07 répond « Identifiants incorrects » à un mauvais mot de passe comme à un compte inconnu', function () {
    accountOnPhone();

    $wrongPassword = attemptLogin('0700000001', 'mauvais-mot-de-passe')->assertUnprocessable();
    $unknown = attemptLogin('0799999999')->assertUnprocessable();

    expect($wrongPassword->json())->toBe($unknown->json())
        ->and($wrongPassword->json('errors.identifier'))->toBe(['Identifiants incorrects.']);
    $this->assertGuest();
});

it('compte les échecs de connexion du jour pour les alertes et les journalise', function () {
    accountOnPhone();

    $response = attemptLogin('0700000001', 'mauvais');
    attemptLogin('0700000002', 'mauvais');

    expect((int) Cache::get(AlertCounter::PREFIX.AttemptLogin::FAILED_ALERT.':'.now()->toDateString()))->toBe(2)
        ->and(ActivityLog::query()->where('request_id', $response->headers->get('X-Request-Id'))->sole())
        ->action->toBe('auth.login')
        ->result->value->toBe('validation_failed')
        ->params->toMatchArray(['identifier' => '0700000001', 'password' => '***']);
});

it('F-AUTH-01 bloque 15 minutes après 5 échecs, même avec le bon mot de passe', function () {
    accountOnPhone();

    foreach (range(1, 4) as $attempt) {
        attemptLogin('0700000001', 'mauvais')->assertUnprocessable();
    }
    $blocked = attemptLogin('0700000001', 'mauvais')->assertStatus(429)
        ->assertJsonPath('code', 'too_many_attempts')
        ->assertJsonPath('message', 'Trop de tentatives, réessayez dans 15 minutes.');

    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(890);
    attemptLogin('0700000001')->assertStatus(429);
    $this->assertGuest();

    $this->travel(16)->minutes();
    attemptLogin('0700000001')->assertOk();
});

it('F-AUTH-01 compte les échecs par identifiant et adresse IP', function () {
    accountOnPhone();
    foreach (range(1, 5) as $attempt) {
        attemptLogin('0700000001', 'mauvais', ip: '10.0.0.1');
    }

    attemptLogin('0700000001', ip: '10.0.0.2')->assertOk();
});

it('F-AUTH-01 remet le compteur d\'échecs à zéro après une connexion réussie', function () {
    accountOnPhone();
    foreach (range(1, 4) as $attempt) {
        attemptLogin('0700000001', 'mauvais');
    }
    attemptLogin('0700000001')->assertOk();

    foreach (range(1, 4) as $attempt) {
        attemptLogin('0700000001', 'mauvais')->assertUnprocessable();
    }
});

it('F-AUTH-01 refuse un compte sans rattachement actif : « Votre accès n\'est pas actif »', function (string $state) {
    $membership = accountOnPhone();
    match ($state) {
        'compte désactivé' => $membership->user->forceFill(['status' => 'disabled'])->save(),
        'rattachement désactivé' => $membership->forceFill(['status' => 'disabled'])->save(),
        'rattachement encore invité' => $membership->forceFill(['status' => 'invited'])->save(),
        'organisation résiliée' => Organization::query()->whereKey($membership->organization_id)->update(['status' => 'terminated']),
    };

    attemptLogin('0700000001')->assertForbidden()
        ->assertJsonPath('code', 'inactive_account')
        ->assertJsonPath('message', 'Votre accès n\'est pas actif. Contactez votre CGA.');
    $this->assertGuest();
})->with(['compte désactivé', 'rattachement désactivé', 'rattachement encore invité', 'organisation résiliée']);

it('F-AUTH-01 accepte un adhérent radié en consultation seule (RG-ADH-12)', function () {
    $membership = accountOnPhone();
    $membership->forceFill(['status' => 'read_only'])->save();

    attemptLogin('0700000001')->assertOk()->assertJsonPath('data.memberships.0.status', 'read_only');
});

it('refuse un invité qui n\'a pas encore choisi son mot de passe', function () {
    $membership = accountOnPhone();
    $membership->user->forceFill(['status' => 'invited', 'password' => null])->save();

    attemptLogin('0700000001')->assertUnprocessable()->assertJsonPath('errors.identifier.0', 'Identifiants incorrects.');
});

it('A04 re-hache en Argon2id un mot de passe haché avec un ancien coût', function () {
    $membership = accountOnPhone();
    $oldHash = Hash::driver('argon2id')->make('password', ['memory' => 512, 'time' => 1]);
    // Écriture directe : le cast « hashed » refuserait une empreinte d'un autre coût.
    DB::table('users')->where('id', $membership->user_id)->update(['password' => $oldHash]);

    attemptLogin('0700000001')->assertOk();

    $newHash = (string) $membership->user->fresh()->password;
    expect($newHash)->not->toBe($oldHash)->toStartWith('$argon2id$')
        ->and(Hash::check('password', $newHash))->toBeTrue();
});

it('valide la demande de connexion', function () {
    $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identifier', 'password']);
});
