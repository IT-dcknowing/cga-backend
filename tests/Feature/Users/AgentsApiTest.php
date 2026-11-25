<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Models\Invitation;
use App\Domain\Auth\Notifications\AccessGrantedNotification;
use App\Domain\Auth\Notifications\InvitationNotification;
use App\Domain\Auth\Support\DevMessages;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Actions\ChangeAgentRoles;
use App\Domain\Users\Actions\DisableAgent;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Spa;

/*
 * Utilisateurs d'une organisation (06-IMPLEMENTATION-PLAN étape 1.4, F-ORG-09, F-ORG-10, F-ORG-02
 * « Ouvrir l'accès ») : invitation, rôles, désactivation, réactivation, renvoi du lien ; envoi de
 * l'invitation par SMS ou e-mail (pilote « log »).
 */

beforeEach(function () {
    Notification::fake();
});

function gerant(?Organization $cga = null): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create($cga === null ? [] : ['organization_id' => $cga->id]);
}

function agentIn(Organization $organization, Role $role = Role::CgaAccountant, array $user = []): Membership
{
    return Membership::factory()->create(['organization_id' => $organization->id, 'role' => $role, 'user_id' => User::factory()->create($user)->id]);
}

/**
 * @return array<string, mixed>
 */
function agentInvitation(array $overrides = []): array
{
    return ['first_name' => 'Awa', 'last_name' => 'Koné', 'phone' => '07 08 09 10 11', 'email' => null, 'roles' => ['cga_accountant', 'cga_legal'], ...$overrides];
}

/**
 * Lien d'activation envoyé à ce compte, lu dans la notification.
 */
function sentLink(User $user): string
{
    $link = null;
    Notification::assertSentTo($user, InvitationNotification::class, function (InvitationNotification $notification) use (&$link): bool {
        $link = $notification->link;

        return true;
    });

    return (string) $link;
}

describe('invitation d\'un agent (F-ORG-09)', function () {
    it('invite un nouvel agent avec plusieurs rôles et lui envoie son lien par SMS', function () {
        $manager = gerant();

        $response = inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Awa Koné')
            ->assertJsonPath('data.phone', '+2250708091011')
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.roles.*.role', ['cga_accountant', 'cga_legal'])
            ->assertJsonPath('data.roles.*.status', ['invited', 'invited'])
            ->assertJsonPath('data.invitation.channel', 'sms')
            ->assertJsonPath('data.can', ['update_roles' => true, 'disable' => true, 'enable' => false, 'resend_invitation' => true])
            ->assertJsonMissingPath('dev_message');

        $user = User::query()->findOrFail($response->json('data.id'));
        expect($user->status)->toBe(UserStatus::Invited)
            ->and($user->memberships()->pluck('invited_by')->unique()->all())->toBe([$manager->user_id])
            ->and(sentLink($user))->toStartWith('http://localhost:5173/activer/');
        Notification::assertSentTo($user, InvitationNotification::class, fn (InvitationNotification $n): bool => $n->via($user) === ['sms']
            && $n->organizationName === $manager->organization->name
            && str_contains($n->toSms($user), 'Activez votre accès (lien valable 7 jours)'));
    });

    it('active tous les rôles de l\'invité avec son lien (RG-ACC-03)', function () {
        $manager = gerant();
        $id = inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation())->json('data.id');
        $user = User::query()->findOrFail($id);
        $token = basename(sentLink($user));

        $this->withHeaders(Spa::headers())->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => 'Un-Mot-De-Passe-Solide-2026', 'accepted_terms' => true])
            ->assertOk();

        expect($user->memberships()->pluck('status')->unique()->all())->toBe([MembershipStatus::Active]);
    });

    it('ajoute l\'accès à un compte déjà actif, sans nouveau lien (RG-ACC-01, 02)', function () {
        $manager = gerant();
        $existing = agentIn(Organization::factory()->create(), Role::CgaAccountant, ['phone' => '+2250708091011', 'first_name' => 'Awa', 'last_name' => 'Déjà-là']);

        inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation(['first_name' => 'Autre', 'roles' => ['cga_secretary']]))
            ->assertCreated()
            ->assertJsonPath('data.id', $existing->user_id)
            ->assertJsonPath('data.last_name', 'Déjà-là')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.invitation', null);

        Notification::assertNotSentTo($existing->user, InvitationNotification::class);
        Notification::assertSentTo($existing->user, AccessGrantedNotification::class, fn (AccessGrantedNotification $n): bool => $n->roles === Role::CgaSecretary->label()
            && $n->via($existing->user) === ['sms', 'mail']
            && str_contains($n->toSms($existing->user), "vous avez désormais accès à l'espace {$manager->organization->name}")
            && $n->toMail($existing->user)->subject === "Nouvel accès à CGA Connect : {$manager->organization->name}");
        $existing->user->email = null;
        expect((new AccessGrantedNotification('CGA', 'Comptable'))->via($existing->user))->toBe(['sms']);
        expect(Invitation::query()->count())->toBe(0);
    });

    it('envoie le lien par e-mail, et exige alors l\'adresse', function () {
        $manager = gerant();

        inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation(['channel' => 'email']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $id = inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation(['channel' => 'sms_email', 'email' => 'awa@centre.ci']))->assertCreated()->json('data.id');

        $user = User::query()->findOrFail($id);
        Notification::assertSentTo($user, InvitationNotification::class, fn (InvitationNotification $n): bool => $n->via($user) === ['sms', 'mail']
            && $n->toMail($user)->actionUrl === $n->link
            && $n->toMail($user)->subject === $manager->organization->name.' vous invite sur CGA Connect');
    });

    it('refuse un compte existant sans e-mail pour un envoi par e-mail', function () {
        agentIn(Organization::factory()->create(), Role::CgaAccountant, ['phone' => '+2250708091011', 'email' => null, 'status' => UserStatus::Invited]);

        inSpace(gerant())->postJson('/api/v1/cga/users', agentInvitation(['channel' => 'email', 'email' => 'awa@centre.ci']))
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Une adresse e-mail est nécessaire pour envoyer l\'invitation par e-mail.');
    });

    it('valide l\'identité, le canal et les rôles attribuables dans l\'espace', function () {
        $manager = gerant();
        User::factory()->create(['email' => 'pris@centre.ci']);

        inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation(['first_name' => '', 'phone' => '12', 'roles' => [], 'channel' => 'pigeon']))
            ->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'phone', 'roles', 'channel']);
        inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation(['roles' => ['cga_manager', 'cncga_manager', 'cga_legal', 'cga_legal']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['roles.0', 'roles.1', 'roles.2']);
        inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation(['email' => 'pris@centre.ci']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    });

    it('refuse une personne déjà agent de l\'organisation, ou dont le compte est désactivé', function () {
        $manager = gerant();
        agentIn($manager->organization, Role::CgaAccountant, ['phone' => '+2250708091011']);
        agentIn(Organization::factory()->create(), Role::CgaAccountant, ['phone' => '+2250708091012', 'status' => UserStatus::Disabled]);

        inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation())->assertStatus(409)->assertJsonPath('code', 'agent_already_member');
        inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation(['phone' => '0708091012']))->assertStatus(409)->assertJsonPath('code', 'user_disabled');
    });

    it('recopie le lien en développement seulement, avec le pilote « log »', function () {
        $user = User::factory()->create(['phone' => '+2250708091011', 'email' => 'awa@centre.ci']);
        $devMessages = app(DevMessages::class);

        expect($devMessages->invitation(InvitationChannel::Sms, $user, 'http://spa/activer/x'))->toBe([]);

        app()->instance('env', 'local');
        config(['auth.echo_dev_messages' => true, 'mail.default' => 'log']);
        expect($devMessages->invitation(InvitationChannel::Sms, $user, 'http://spa/activer/x'))->toBe(['dev_message' => ['channel' => 'sms', 'to' => '+2250708091011', 'link' => 'http://spa/activer/x']])
            ->and($devMessages->invitation(InvitationChannel::Email, $user, 'http://spa/activer/x')['dev_message']['to'])->toBe('awa@centre.ci')
            ->and($devMessages->invitation(InvitationChannel::Sms, $user, null))->toBe([]);
    });
});

describe('équipe du CNCGA (F-ORG-10)', function () {
    it('laisse la Présidente inviter un agent du CNCGA avec les rôles du réseau seulement', function () {
        $president = Membership::factory()->role(Role::CncgaPresident)->create();

        inSpace($president)->postJson('/api/v1/network/users', agentInvitation(['roles' => ['cncga_dues', 'cncga_viewer']]))
            ->assertCreated()
            ->assertJsonPath('data.roles.*.role', ['cncga_dues', 'cncga_viewer']);
        inSpace($president)->postJson('/api/v1/network/users', agentInvitation(['phone' => '0708091012', 'roles' => ['cga_accountant']]))
            ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    });
});

describe('droits sur les agents (PRD §3.3)', function () {
    dataset('rôles sans le droit', [
        'expert-comptable du CGA' => [Role::CgaAccountantExpert, '/api/v1/cga/users'],
        'comptable du CGA' => [Role::CgaAccountant, '/api/v1/cga/users'],
        'gestionnaire du CNCGA' => [Role::CncgaManager, '/api/v1/network/users'],
        'administrateur de la plateforme' => [Role::PlatformAdmin, '/api/v1/network/users'],
        'Présidente dans l\'espace d\'un CGA' => [Role::CncgaPresident, '/api/v1/cga/users'],
        'gérant dans l\'espace du réseau' => [Role::CgaManager, '/api/v1/network/users'],
    ]);

    it('refuse la gestion des agents sans le droit verrouillé de l\'espace', function (Role $role, string $uri) {
        $member = Membership::factory()->role($role)->create();
        $agent = agentIn($member->organization);

        inSpace($member)->getJson($uri)->assertForbidden();
        inSpace($member)->postJson($uri, agentInvitation())->assertForbidden();
        inSpace($member)->getJson("{$uri}/{$agent->user_id}")->assertForbidden();
        inSpace($member)->putJson("{$uri}/{$agent->user_id}/roles", ['roles' => ['cga_legal']])->assertForbidden();
        inSpace($member)->postJson("{$uri}/{$agent->user_id}/disable")->assertForbidden();
        inSpace($member)->postJson("{$uri}/{$agent->user_id}/enable")->assertForbidden();
        inSpace($member)->postJson("{$uri}/{$agent->user_id}/invitation")->assertForbidden();
    })->with('rôles sans le droit');

    it('ne laisse pas le gérant modifier le gérant, ni lui-même', function () {
        $manager = gerant();
        $other = gerant($manager->organization);

        inSpace($manager)->putJson("/api/v1/cga/users/{$other->user_id}/roles", ['roles' => ['cga_legal']])->assertForbidden();
        inSpace($manager)->postJson("/api/v1/cga/users/{$manager->user_id}/disable")->assertForbidden();
        inSpace($manager)->getJson("/api/v1/cga/users/{$other->user_id}")->assertOk()->assertJsonPath('data.can.update_roles', false);
        expect(fn () => app(DisableAgent::class)->execute($manager->organization, $manager->user, $manager->user))->toThrow(BusinessRuleException::class)
            ->and(fn () => app(ChangeAgentRoles::class)->execute($manager->organization, $manager->user, [Role::CgaLegal], $manager->user))->toThrow(BusinessRuleException::class);
    });

    it('ne trouve que les agents de l\'organisation active', function () {
        $manager = gerant();
        $elsewhere = agentIn(Organization::factory()->create());
        $member = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $manager->organization_id]);

        inSpace($manager)->getJson("/api/v1/cga/users/{$elsewhere->user_id}")->assertNotFound();
        inSpace($manager)->getJson("/api/v1/cga/users/{$member->user_id}")->assertNotFound();
        inSpace($manager)->postJson("/api/v1/cga/users/{$elsewhere->user_id}/disable")->assertNotFound();
    });
});

describe('liste des agents', function () {
    it('liste les agents de l\'organisation, filtrés par état, rôle et texte', function () {
        $manager = gerant();
        $cga = $manager->organization;
        agentIn($cga, Role::CgaAccountant, ['last_name' => 'Bamba']);
        agentIn($cga, Role::CgaLegal, ['last_name' => 'Coulibaly', 'status' => UserStatus::Invited])->forceFill(['status' => MembershipStatus::Invited])->save();
        agentIn($cga, Role::CgaSecretary, ['last_name' => 'Diallo'])->forceFill(['status' => MembershipStatus::Disabled])->save();
        agentIn(Organization::factory()->create(), Role::CgaAccountant, ['last_name' => 'Ailleurs']);

        $names = fn (string $query = ''): array => array_column((array) inSpace($manager)->getJson('/api/v1/cga/users'.$query)->assertOk()->json('data'), 'last_name');

        expect($names())->toHaveCount(4)->not->toContain('Ailleurs')
            ->and($names('?filter[status]=active'))->toEqualCanonicalizing(['Bamba', $manager->user->last_name])
            ->and($names('?filter[status]=invited'))->toBe(['Coulibaly'])
            ->and($names('?filter[status]=disabled'))->toBe(['Diallo'])
            ->and($names('?filter[role]=cga_legal'))->toBe(['Coulibaly'])
            ->and($names('?filter[search]=coulib'))->toBe(['Coulibaly'])
            ->and($names('?sort=-last_name'))->toBe(collect($names())->sortDesc()->values()->all());
        inSpace($manager)->getJson('/api/v1/cga/users?filter[status]=read_only')->assertUnprocessable();
    });
});

describe('rôles, désactivation, réactivation', function () {
    it('remplace les rôles d\'un agent : rôle retiré supprimé, rôle ajouté actif', function () {
        $manager = gerant();
        $agent = agentIn($manager->organization, Role::CgaAccountant);

        inSpace($manager)->putJson("/api/v1/cga/users/{$agent->user_id}/roles", ['roles' => ['cga_legal', 'cga_secretary']])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.roles', [
                ['role' => 'cga_legal', 'role_label' => Role::CgaLegal->label(), 'status' => 'active'],
                ['role' => 'cga_secretary', 'role_label' => Role::CgaSecretary->label(), 'status' => 'active'],
            ]);

        expect(Membership::query()->find($agent->id))->toBeNull()
            ->and(AuditLog::query()->where('event', 'deleted')->where('subject_id', $agent->id)->exists())->toBeTrue();
        inSpace($manager)->putJson("/api/v1/cga/users/{$agent->user_id}/roles", ['roles' => ['cga_manager']])->assertUnprocessable();
    });

    it('donne à un invité des rôles en attente, couverts par son lien', function () {
        $manager = gerant();
        $id = inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation(['roles' => ['cga_accountant']]))->json('data.id');

        inSpace($manager)->putJson("/api/v1/cga/users/{$id}/roles", ['roles' => ['cga_accountant', 'cga_trainer']])
            ->assertOk()->assertJsonPath('data.roles.*.status', ['invited', 'invited']);
    });

    it('désactive tous les rôles d\'un agent : sa session dans l\'espace s\'arrête, ses autres accès restent', function () {
        $manager = gerant();
        $agent = agentIn($manager->organization, Role::CgaAccountant);
        $other = Membership::factory()->create(['user_id' => $agent->user_id]);

        inSpace($manager)->postJson("/api/v1/cga/users/{$agent->user_id}/disable")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.can.enable', true);

        inSpace($agent)->getJson('/api/v1/referentials/sectors')->assertStatus(409)->assertJsonPath('code', 'space_required');
        inSpace($other)->getJson('/api/v1/referentials/sectors')->assertOk();
        inSpace($manager)->postJson("/api/v1/cga/users/{$agent->user_id}/disable")->assertStatus(409)->assertJsonPath('code', 'agent_already_disabled');
    });

    it('réactive tous les rôles d\'un agent désactivé, y compris ceux changés entre-temps', function () {
        $manager = gerant();
        $agent = agentIn($manager->organization, Role::CgaAccountant);
        inSpace($manager)->postJson("/api/v1/cga/users/{$agent->user_id}/disable")->assertOk();
        inSpace($manager)->putJson("/api/v1/cga/users/{$agent->user_id}/roles", ['roles' => ['cga_trainer', 'cga_legal']])
            ->assertOk()->assertJsonPath('data.status', 'disabled');

        inSpace($manager)->postJson("/api/v1/cga/users/{$agent->user_id}/enable")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.roles.*.role', ['cga_legal', 'cga_trainer'])
            ->assertJsonPath('data.roles.*.status', ['active', 'active']);
        inSpace($manager)->postJson("/api/v1/cga/users/{$agent->user_id}/enable")->assertStatus(409)->assertJsonPath('code', 'agent_not_disabled');
    });

    it('rend invités les rôles réactivés d\'un compte qui n\'a pas encore activé son accès', function () {
        $manager = gerant();
        $id = inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation(['roles' => ['cga_accountant']]))->json('data.id');
        inSpace($manager)->postJson("/api/v1/cga/users/{$id}/disable")->assertOk();

        inSpace($manager)->postJson("/api/v1/cga/users/{$id}/enable")->assertOk()->assertJsonPath('data.status', 'invited');
    });
});

describe('renvoi du lien d\'activation (RG-ACC-03)', function () {
    it('renvoie un nouveau lien, qui annule le précédent', function () {
        $manager = gerant();
        $id = inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation(['email' => 'awa@centre.ci']))->json('data.id');
        $first = Invitation::query()->sole();

        inSpace($manager)->postJson("/api/v1/cga/users/{$id}/invitation", ['channel' => 'email'])
            ->assertOk()->assertJsonPath('data.invitation.channel', 'email');

        expect($first->refresh()->revoked_at)->not->toBeNull()
            ->and(Invitation::query()->whereNull('revoked_at')->count())->toBe(1);
        Notification::assertSentToTimes(User::query()->findOrFail($id), InvitationNotification::class, 2);
    });

    it('refuse le renvoi à un agent actif, ou par e-mail sans adresse', function () {
        $manager = gerant();
        $active = agentIn($manager->organization);
        $id = inSpace($manager)->postJson('/api/v1/cga/users', agentInvitation())->json('data.id');

        inSpace($manager)->postJson("/api/v1/cga/users/{$active->user_id}/invitation")->assertStatus(409)->assertJsonPath('code', 'agent_not_invited');
        inSpace($manager)->postJson("/api/v1/cga/users/{$id}/invitation", ['channel' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        inSpace($manager)->postJson("/api/v1/cga/users/{$id}/invitation", ['channel' => 'fax'])->assertUnprocessable()->assertJsonValidationErrors('channel');
    });
});

describe('ouverture de l\'accès d\'un CGA (F-ORG-02)', function () {
    it('invite le gérant d\'un CGA, montré sur sa fiche du registre', function () {
        $cga = Organization::factory()->create();

        $response = inSpace(Membership::factory()->role(Role::CncgaManager)->create())
            ->postJson("/api/v1/network/organizations/{$cga->id}/manager", agentInvitation(['roles' => null]))
            ->assertCreated()
            ->assertJsonPath('data.manager.name', 'Awa Koné')
            ->assertJsonPath('data.manager.status', 'invited')
            ->assertJsonPath('data.can.invite_manager', true);

        $user = User::query()->findOrFail($response->json('data.manager.id'));
        expect($user->memberships()->sole()->role)->toBe(Role::CgaManager);
        Notification::assertSentTo($user, InvitationNotification::class, fn (InvitationNotification $n): bool => $n->organizationName === $cga->name);
    });

    it('renvoie le lien au gérant encore en attente, et refuse un second gérant', function () {
        $cga = Organization::factory()->create();
        $network = Membership::factory()->role(Role::CncgaManager)->create();
        inSpace($network)->postJson("/api/v1/network/organizations/{$cga->id}/manager", agentInvitation())->assertCreated();

        inSpace($network)->postJson("/api/v1/network/organizations/{$cga->id}/manager", agentInvitation())->assertCreated();
        expect(Invitation::query()->whereNull('revoked_at')->count())->toBe(1)->and(Invitation::query()->count())->toBe(2);

        inSpace($network)->postJson("/api/v1/network/organizations/{$cga->id}/manager", agentInvitation(['phone' => '0708091012']))
            ->assertStatus(409)->assertJsonPath('code', 'cga_manager_exists');
        gerant($active = Organization::factory()->create());
        inSpace($network)->postJson("/api/v1/network/organizations/{$active->id}/manager", agentInvitation())
            ->assertStatus(409)->assertJsonPath('code', 'cga_manager_exists');
    });

    it('réserve l\'ouverture de l\'accès au CNCGA, pour un CGA seulement', function () {
        $cga = Organization::factory()->create();
        $network = Membership::factory()->role(Role::CncgaManager)->create();

        inSpace(Membership::factory()->role(Role::CncgaViewer)->create())->postJson("/api/v1/network/organizations/{$cga->id}/manager", agentInvitation())->assertForbidden();
        inSpace(gerant($cga))->postJson("/api/v1/network/organizations/{$cga->id}/manager", agentInvitation())->assertForbidden();
        inSpace($network)->postJson("/api/v1/network/organizations/{$network->organization_id}/manager", agentInvitation())->assertForbidden();
        inSpace($network)->postJson("/api/v1/network/organizations/{$cga->id}/manager", agentInvitation(['last_name' => '']))->assertUnprocessable()->assertJsonValidationErrors('last_name');
    });
});
