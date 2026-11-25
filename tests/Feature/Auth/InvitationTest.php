<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Models\Invitation;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\Spa;

/*
 * F-AUTH-02 Activation par invitation (RG-ACC-03, 03-APPFLOW §2) : lien valable 7 jours, à usage
 * unique ; mot de passe d'au moins 8 caractères ; conditions d'utilisation ; consentements SMS /
 * WhatsApp pour un adhérent (RG-NOT-04). Un lien expiré ou déjà utilisé affiche « Lien invalide »
 * avec le contact de l'organisation qui invite.
 */

const INVITATION_TOKEN = 'jeton-de-test-0123456789abcdefghijklmnopqr';

function invitation(Role $role = Role::CgaAccountant, array $attributes = []): Invitation
{
    $organization = Organization::factory()->create([
        'type' => $role->organizationType(),
        'name' => 'DC-KNOWING CGA',
        'phone_1' => '+2252722000000',
        'email' => 'contact@dcknowing.ci',
    ]);
    $membership = Membership::factory()->invited()->create([
        'user_id' => User::factory()->invited()->create(['first_name' => 'Awa', 'phone' => '+2250700000001', 'email' => 'awa@cga.ci']),
        'organization_id' => $organization->id,
        'role' => $role,
        'member_id' => $role->isMemberRole() ? Member::factory()->create(['organization_id' => $organization->id])->id : null,
    ]);

    return Invitation::factory()->token(INVITATION_TOKEN)->create(['membership_id' => $membership->id, ...$attributes]);
}

function readInvitation(string $token = INVITATION_TOKEN): TestResponse
{
    return test()->withHeaders(Spa::headers())->getJson('/api/v1/auth/invitations/'.$token);
}

/**
 * @param  array<string, mixed>  $input
 */
function acceptInvitation(array $input = [], string $token = INVITATION_TOKEN): TestResponse
{
    return test()->withHeaders(Spa::headers())->postJson("/api/v1/auth/invitations/{$token}/accept", [
        'password' => 'Abidjan-Plateau-2026',
        'accepted_terms' => true,
        ...$input,
    ]);
}

it('F-AUTH-02 affiche le prénom, l\'organisation qui invite et le rôle', function () {
    invitation();

    readInvitation()->assertOk()->assertExactJson(['data' => [
        'status' => 'valid',
        'first_name' => 'Awa',
        'organization_name' => 'DC-KNOWING CGA',
        'organization_contact' => '+2252722000000 · contact@dcknowing.ci',
        'role' => 'cga_accountant',
        'role_label' => 'Comptable',
        'is_member' => false,
    ]]);
});

it('F-AUTH-02 signale une invitation d\'adhérent, qui demande en plus ses choix SMS / WhatsApp', function () {
    invitation(Role::MemberOwner);

    readInvitation()->assertOk()->assertJsonPath('data.is_member', true);
});

it('F-AUTH-02 répond « Lien invalide » avec le contact de l\'organisation pour un lien expiré, utilisé ou révoqué', function (string $case) {
    $invitation = invitation();
    match ($case) {
        'expiré' => $invitation->forceFill(['expires_at' => now()->subMinute()])->save(),
        'déjà utilisé' => $invitation->forceFill(['accepted_at' => now()])->save(),
        'révoqué' => $invitation->forceFill(['revoked_at' => now()])->save(),
        'rattachement désactivé' => $invitation->membership->forceFill(['status' => 'disabled'])->save(),
        'compte désactivé' => $invitation->membership->user->forceFill(['status' => 'disabled'])->save(),
        'organisation résiliée' => $invitation->membership->organization->forceFill(['status' => 'terminated'])->save(),
    };

    readInvitation()->assertOk()->assertExactJson(['data' => [
        'status' => 'invalid',
        'organization_contact' => '+2252722000000 · contact@dcknowing.ci',
    ]]);
    acceptInvitation()->assertStatus(409)
        ->assertJsonPath('code', 'invitation_invalid')
        ->assertJsonPath('message', 'Ce lien n\'est plus valide.')
        ->assertJsonPath('details.organization_contact', '+2252722000000 · contact@dcknowing.ci');
})->with(['expiré', 'déjà utilisé', 'révoqué', 'rattachement désactivé', 'compte désactivé', 'organisation résiliée']);

it('A07 répond 404 à un jeton inconnu, avant toute validation', function () {
    invitation();

    readInvitation('jeton-inconnu')->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/invitations/jeton-inconnu/accept', [])->assertNotFound();
});

it('F-AUTH-02 active le compte et le rattachement, puis ouvre la session dans cet espace', function () {
    $invitation = invitation();

    $response = acceptInvitation()->assertOk()
        ->assertJsonPath('data.active_membership_id', $invitation->membership_id)
        ->assertJsonPath('data.user.first_name', 'Awa');

    $user = $invitation->membership->user->fresh();
    $membership = $invitation->membership->fresh();
    expect($user->status->value)->toBe('active')
        ->and(Hash::check('Abidjan-Plateau-2026', (string) $user->password))->toBeTrue()
        ->and($user->phone_verified_at)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->last_space_membership_id)->toBe($membership->id)
        ->and($membership->status->value)->toBe('active')
        ->and($membership->activated_at)->not->toBeNull()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and(session(TenantContext::SESSION_KEY))->toBe($membership->id)
        ->and(ActivityLog::query()->where('request_id', $response->headers->get('X-Request-Id'))->sole())
        ->action->toBe('auth.invitations.accept')
        ->subject_type->toBe(Invitation::class)
        ->params->toMatchArray(['password' => '***', 'accepted_terms' => true]);
    $this->assertAuthenticatedAs($user);
    // Aucun consentement n'est recueilli pour un agent.
    expect(NotificationPreference::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('RG-ACC-03 n\'accepte un lien qu\'une fois', function () {
    invitation();
    acceptInvitation()->assertOk();

    acceptInvitation()->assertStatus(409)->assertJsonPath('code', 'invitation_invalid');
});

it('tient l\'e-mail pour vérifié quand le lien est parti par e-mail', function () {
    $invitation = invitation(attributes: ['channel' => 'email']);

    acceptInvitation()->assertOk();

    expect($invitation->membership->user->fresh())
        ->email_verified_at->not->toBeNull()
        ->phone_verified_at->toBeNull();
});

it('RG-NOT-04 recueille les consentements SMS / WhatsApp d\'un adhérent, datés', function () {
    $invitation = invitation(Role::MemberOwner);

    acceptInvitation(['sms_opt_in' => true, 'whatsapp_opt_in' => false])->assertOk()
        ->assertJsonPath('data.user.notification_preferences', ['sms' => true, 'whatsapp' => false]);

    $preferences = NotificationPreference::query()->where('user_id', $invitation->membership->user_id)->get()->keyBy(fn ($p) => $p->channel->value);
    expect($preferences['sms']->opted_out)->toBeFalse()
        ->and($preferences['sms']->consented_at)->not->toBeNull()
        ->and($preferences['whatsapp']->opted_out)->toBeTrue()
        ->and($preferences['whatsapp']->consented_at)->toBeNull()
        ->and(AuditLog::query()->where('event', 'notification_preferences_updated')->sole()->changes)
        ->toEqual(['sms' => [null, true], 'whatsapp' => [null, false]]);
});

it('active l\'accès d\'un compte déjà actif dans un autre espace', function () {
    $invitation = invitation();
    $user = $invitation->membership->user;
    $user->forceFill(['status' => 'active'])->save();
    Membership::factory()->create(['user_id' => $user->id]);

    acceptInvitation()->assertOk()->assertJsonCount(2, 'data.memberships');
});

it('valide le mot de passe et les conditions d\'utilisation, sans consommer le lien', function (array $input, string $field) {
    $invitation = invitation();

    acceptInvitation($input)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($invitation->fresh()->accepted_at)->toBeNull();
})->with([
    'mot de passe trop court' => [['password' => 'court'], 'password'],
    'mot de passe courant' => [['password' => 'football'], 'password'],
    'conditions refusées' => [['accepted_terms' => false], 'accepted_terms'],
    'consentement mal formé' => [['sms_opt_in' => 'peut-être'], 'sms_opt_in'],
]);
