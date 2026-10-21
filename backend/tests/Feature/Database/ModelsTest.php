<?php

use App\Domain\Audit\Models\PlatformAccessLog;
use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Auth\Models\Invitation;
use App\Domain\Auth\Models\OtpCode;
use App\Domain\Documents\Models\File;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Models\Setting;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Modèles du socle : relations, conversions et protection des champs fixés par le serveur.
 */

function storedFile(?Organization $organization = null, ?User $uploader = null): File
{
    $file = new File;
    $file->organization_id = $organization?->id;
    $file->disk = 'private';
    $file->path = 'orgs/'.Str::ulid().'.pdf';
    $file->original_name = 'contrat.pdf';
    $file->mime_type = 'application/pdf';
    $file->size_bytes = 1024;
    $file->sha256 = hash('sha256', 'contenu');
    $file->uploaded_by = $uploader?->id;
    $file->save();

    return $file;
}

it('relie un utilisateur à ses rattachements et à son dernier espace', function () {
    $membership = Membership::factory()->create();
    $user = $membership->user;
    $user->last_space_membership_id = $membership->id;
    $user->save();

    expect($user->memberships)->toHaveCount(1)
        ->and($user->fresh()->lastSpaceMembership->is($membership))->toBeTrue()
        ->and($membership->organization->memberships->first()->is($membership))->toBeTrue();
});

it('hache le mot de passe et ne l\'expose jamais', function () {
    $user = User::factory()->create(['password' => 'secret-de-test']);

    expect(Hash::check('secret-de-test', $user->password))->toBeTrue()
        ->and($user->toArray())->not->toHaveKey('password');
});

it('donne le nom complet d\'un utilisateur', function () {
    expect(User::factory()->make(['first_name' => 'Awa', 'last_name' => 'Koné'])->fullName())->toBe('Awa Koné');
});

it('ne laisse remplir en masse ni le statut, ni le rôle, ni l\'organisation', function () {
    $user = new User(['first_name' => 'A', 'last_name' => 'B', 'phone' => '+2250700000001', 'status' => 'active', 'password' => 'x']);
    $organization = new Organization(['name' => 'X', 'type' => 'cncga', 'status' => 'suspended', 'numbering_abbreviation' => 'ABC']);

    expect($user->getAttributes())->not->toHaveKeys(['status', 'password'])
        ->and($organization->getAttributes())->not->toHaveKeys(['type', 'status', 'numbering_abbreviation'])
        ->and(fn () => new Membership(['role' => 'platform_admin', 'organization_id' => 'x']))->toThrow(MassAssignmentException::class);
});

it('rattache chaque rôle au bon type d\'organisation', function (Role $role, OrganizationType $type) {
    $membership = Membership::factory()->role($role)->create();

    expect($membership->organization->type)->toBe($type)
        ->and($membership->role)->toBe($role);
})->with([
    [Role::PlatformAdmin, OrganizationType::Platform],
    [Role::CncgaPresident, OrganizationType::Cncga],
    [Role::CgaManager, OrganizationType::Cga],
    [Role::MemberOwner, OrganizationType::Cga],
]);

it('relie une invitation à son rattachement et à son invitant, sans exposer l\'empreinte du jeton', function () {
    $inviter = User::factory()->create();
    $membership = Membership::factory()->invited()->create(['invited_by' => $inviter->id]);
    $invitation = new Invitation;
    $invitation->membership_id = $membership->id;
    $invitation->token_hash = hash('sha256', 'jeton');
    $invitation->channel = InvitationChannel::Sms;
    $invitation->sent_at = now();
    $invitation->expires_at = now()->addDays(7);
    $invitation->save();

    expect($invitation->membership->is($membership))->toBeTrue()
        ->and($membership->invitations)->toHaveCount(1)
        ->and($membership->inviter->is($inviter))->toBeTrue()
        ->and($invitation->toArray())->not->toHaveKey('token_hash');
});

it('relie un code SMS à son utilisateur, sans exposer son empreinte', function () {
    $user = User::factory()->create();
    $code = new OtpCode;
    $code->user_id = $user->id;
    $code->purpose = OtpPurpose::PasswordReset;
    $code->target = $user->phone;
    $code->code_hash = hash('sha256', '123456');
    $code->expires_at = now()->addMinutes(10);
    $code->save();

    expect($code->user->is($user))->toBeTrue()
        ->and($code->fresh()->attempts)->toBe(0)
        ->and($code->toArray())->not->toHaveKey('code_hash');
});

it('relie un fichier à son organisation, à son auteur et aux images du CGA', function () {
    $organization = Organization::factory()->create();
    $uploader = User::factory()->create();
    $logo = storedFile($organization, $uploader);
    $signature = storedFile($organization);
    $stamp = storedFile($organization);
    $organization->logo_file_id = $logo->id;
    $organization->signature_file_id = $signature->id;
    $organization->stamp_file_id = $stamp->id;
    $organization->save();

    $organization = $organization->fresh();
    expect($logo->organization->is($organization))->toBeTrue()
        ->and($logo->uploader->is($uploader))->toBeTrue()
        ->and($organization->logo->is($logo))->toBeTrue()
        ->and($organization->signature->is($signature))->toBeTrue()
        ->and($organization->stamp->is($stamp))->toBeTrue();
});

it('garde la valeur d\'un paramètre en JSON, rattachée à son organisation', function () {
    $organization = Organization::factory()->create();
    $setting = new Setting;
    $setting->organization_id = $organization->id;
    $setting->key = 'messaging.send_window';
    $setting->value = ['from' => '07:00', 'to' => '20:00'];
    $setting->save();

    // jsonb réordonne les clés : la comparaison porte sur les valeurs.
    expect($setting->fresh()->value)->toEqual(['from' => '07:00', 'to' => '20:00'])
        ->and($setting->organization->is($organization))->toBeTrue();
});

it('relie un accès de l\'équipe technique à son administrateur et au CGA concerné', function () {
    $admin = User::factory()->create();
    $cga = Organization::factory()->create();
    $access = new PlatformAccessLog;
    $access->admin_user_id = $admin->id;
    $access->organization_id = $cga->id;
    $access->reason = 'Maintenance';
    $access->started_at = now();
    $access->save();

    expect($access->admin->is($admin))->toBeTrue()
        ->and($access->organization->is($cga))->toBeTrue();
});
