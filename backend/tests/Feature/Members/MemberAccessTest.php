<?php

use App\Domain\Auth\Notifications\AccessGrantedNotification;
use App\Domain\Auth\Notifications\InvitationNotification;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\Spa;

/*
 * Accès en ligne de l'adhérent (06-IMPLEMENTATION-PLAN étape 2.5, F-ADH-09) : accès principal et
 * secondaires invités par le gérant (rôles member_owner et member_user), renvoi, désactivation.
 */

beforeEach(function () {
    Notification::fake();
});

function accessManager(): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create();
}

function accessMember(Membership $manager, array $overrides = []): Member
{
    return Member::factory()->active()->create(['organization_id' => $manager->organization_id, ...$overrides]);
}

function inviteAccess(Membership $agent, Member $member, array $overrides = []): TestResponse
{
    return inSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/accesses", [
        'first_name' => 'Aya', 'last_name' => 'Kouamé', 'phone' => '07 11 22 33 44', 'role' => 'member_owner', ...$overrides,
    ]);
}

function accessAction(Membership $agent, Member $member, string $accessId, string $action, array $input = []): TestResponse
{
    return inSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/accesses/{$accessId}/{$action}", $input);
}

it('F-ADH-09 invite l\'accès principal par SMS ; activé, il ouvre l\'espace de l\'adhérent', function () {
    $manager = accessManager();
    $member = accessMember($manager);

    $access = inviteAccess($manager, $member)
        ->assertCreated()
        ->assertJsonPath('data.role', 'member_owner')
        ->assertJsonPath('data.first_name', 'Aya')
        ->assertJsonPath('data.phone', '+2250711223344')
        ->assertJsonPath('data.status', 'invited')
        ->assertJsonPath('data.invitation.channel', 'sms')
        ->assertJsonPath('data.can.resend_invitation', true)
        ->json('data.id');

    $user = User::query()->where('phone', '+2250711223344')->sole();
    expect(Membership::query()->findOrFail($access)->member_id)->toBe($member->id);
    $link = '';
    Notification::assertSentTo($user, InvitationNotification::class, function (InvitationNotification $notification) use (&$link): bool {
        $link = $notification->link;

        return true;
    });

    $token = basename(parse_url($link, PHP_URL_PATH) ?: '');
    $this->withHeaders(Spa::headers())->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => 'Abidjan-Plateau-2026', 'accepted_terms' => true])->assertOk();
    expect(Membership::query()->findOrFail($access)->status)->toBe(MembershipStatus::Active);
    $this->flushSession();
    auth()->forgetGuards();

    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}/accesses")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.0.invitation', null)
        ->assertJsonPath('data.0.can.disable', true);
});

it('donne un accès secondaire à un compte existant ; refuse un second accès principal et un doublon', function () {
    $manager = accessManager();
    $member = accessMember($manager);
    $agent = Membership::factory()->role(Role::CgaAccountant)->create();
    inviteAccess($manager, $member)->assertCreated();

    inviteAccess($manager, $member, ['phone' => $agent->user->phone, 'role' => 'member_user'])
        ->assertCreated()
        ->assertJsonPath('data.role', 'member_user')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.first_name', $agent->user->first_name);
    Notification::assertSentTo($agent->user, AccessGrantedNotification::class);

    inviteAccess($manager, $member, ['phone' => '0722334455'])->assertStatus(409)->assertJsonPath('code', 'member_owner_exists');
    inviteAccess($manager, $member, ['role' => 'member_user'])->assertStatus(409)->assertJsonPath('code', 'member_access_exists');
    inviteAccess($manager, $member, ['role' => 'cga_manager', 'phone' => ''])->assertUnprocessable()->assertJsonValidationErrors(['role', 'phone']);
    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}/accesses")->assertJsonCount(2, 'data')->assertJsonPath('data.0.role', 'member_owner');
});

it('renvoie l\'invitation, désactive puis réactive un accès', function () {
    $manager = accessManager();
    $member = accessMember($manager);
    $owner = inviteAccess($manager, $member)->json('data.id');
    $user = User::query()->where('phone', '+2250711223344')->sole();

    accessAction($manager, $member, $owner, 'resend', ['channel' => 'sms'])->assertOk()->assertJsonPath('data.status', 'invited');
    Notification::assertSentToTimes($user, InvitationNotification::class, 2);

    accessAction($manager, $member, $owner, 'disable')->assertOk()->assertJsonPath('data.status', 'disabled')->assertJsonPath('data.can.enable', true);
    accessAction($manager, $member, $owner, 'disable')->assertStatus(409)->assertJsonPath('code', 'access_already_disabled');
    accessAction($manager, $member, $owner, 'resend')->assertStatus(409)->assertJsonPath('code', 'access_not_invited');

    $second = inviteAccess($manager, $member, ['phone' => '0722334455'])->assertCreated()->json('data.id');
    accessAction($manager, $member, $owner, 'enable')->assertStatus(409)->assertJsonPath('code', 'member_owner_exists');
    accessAction($manager, $member, $second, 'disable')->assertOk();
    accessAction($manager, $member, $owner, 'enable')->assertOk()->assertJsonPath('data.status', 'invited');
    accessAction($manager, $member, $owner, 'enable')->assertStatus(409)->assertJsonPath('code', 'access_not_disabled');
});

it('réserve la gestion des accès au gérant, refuse un brouillon, un dossier radié et l\'accès d\'un autre dossier', function () {
    $manager = accessManager();
    $member = accessMember($manager);
    $expert = Membership::factory()->role(Role::CgaAccountantExpert)->create(['organization_id' => $manager->organization_id]);
    $owner = inviteAccess($manager, $member)->json('data.id');

    inviteAccess($expert, $member, ['phone' => '0722334455'])->assertForbidden();
    accessAction($expert, $member, $owner, 'disable')->assertForbidden();
    inSpace($expert)->getJson("/api/v1/cga/members/{$member->id}/accesses")->assertOk()->assertJsonPath('data.0.can.disable', false);

    inviteAccess($manager, Member::factory()->create(['organization_id' => $manager->organization_id]))->assertStatus(409)->assertJsonPath('code', 'member_draft');
    inviteAccess($manager, accessMember($manager, ['status' => MemberStatus::Radiated]))->assertStatus(409)->assertJsonPath('code', 'member_closed');
    accessAction($manager, accessMember($manager), $owner, 'disable')->assertNotFound();

    accessAction($manager, $member, $owner, 'disable')->assertOk();
    Member::query()->withoutGlobalScopes()->whereKey($member->id)->update(['status' => MemberStatus::Radiated]);
    accessAction($manager, $member, $owner, 'enable')->assertStatus(409)->assertJsonPath('code', 'member_closed');
    expect(Membership::query()->findOrFail($owner)->status)->toBe(MembershipStatus::Disabled);
});
