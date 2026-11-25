<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Users\Actions\ResetUserPermissions;
use App\Domain\Users\Actions\SaveUserPermissions;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\RolePermission;
use App\Domain\Users\Models\User;
use App\Domain\Users\Models\UserPermissionOverride;
use App\Domain\Users\Permissions\PermissionCatalog;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Tenancy\TenantContext;

/*
 * Habilitations par utilisateur (06-IMPLEMENTATION-PLAN étape 1.11, RG-ROLE-07) : le « nuage »
 * d'un agent — valeur et origine de chaque droit, ajustements, retour au profil — par le gérant pour
 * son centre, par l'administrateur de la plateforme pour le CNCGA.
 */

/**
 * @return array<string, array<string, mixed>> droits du nuage par code
 */
function cloudOf(Membership $actor, string $uri): array
{
    $modules = inSpace($actor)->getJson($uri)->assertOk()->json('data.modules');

    return collect($modules)->flatMap(fn (array $module) => collect($module['permissions'])->keyBy('code'))->all();
}

/**
 * Droits accordés du nuage, modifiés.
 *
 * @param  list<string>  $add
 * @param  list<string>  $remove
 * @return list<string>
 */
function adjusted(array $cloud, array $add = [], array $remove = []): array
{
    return [...array_values(array_diff(array_keys(array_filter($cloud, fn (array $p) => $p['granted'])), $remove)), ...$add];
}

it('montre le nuage d\'un agent : valeur, origine, profil, verrous, groupés par module', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $accountant = Membership::factory()->create(['organization_id' => $manager->organization_id]);

    $data = inSpace($manager)->getJson("/api/v1/cga/users/{$accountant->user_id}/permissions")->assertOk()->json('data');
    $cloud = collect($data['modules'])->flatMap(fn (array $m) => $m['permissions'])->keyBy('code');

    expect($data['user'])->toBe(['id' => $accountant->user_id, 'name' => $accountant->user->fullName()])
        ->and($data['roles'])->toBe([['role' => 'cga_accountant', 'role_label' => Role::CgaAccountant->label()]])
        ->and($data['adjusted_count'])->toBe(0)
        ->and($cloud['members.update'])->toMatchArray(['granted' => true, 'profile' => true, 'adjusted' => false, 'origin' => 'network', 'locked' => false, 'module' => 'members'])
        ->and($cloud['members.radiation.cancel'])->toMatchArray(['granted' => false, 'locked' => true, 'origin' => 'locked']);
});

it('RG-ROLE-07 fait primer un retrait individuel sur le profil, même ajusté par le centre, et ignore les verrous', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $accountant = Membership::factory()->create(['organization_id' => $manager->organization_id]);
    $uri = "/api/v1/cga/users/{$accountant->user_id}/permissions";
    $centre = new RolePermission;
    $centre->organization_id = $manager->organization_id;
    $centre->role = Role::CgaAccountant;
    $centre->permission = 'documents.cancel';
    $centre->granted = true;
    $centre->save();

    $granted = adjusted(cloudOf($manager, $uri), add: ['attestations.block', 'members.radiation.cancel'], remove: ['members.update', 'documents.cancel']);
    inSpace($manager)->putJson($uri, ['permissions' => $granted, 'reason' => 'Stagiaire'])
        ->assertOk()
        ->assertJsonPath('data.adjusted_count', 3);

    $resolver = app(PermissionResolver::class);
    expect($resolver->allows($accountant, 'members.update'))->toBeFalse()
        ->and($resolver->allows($accountant, 'documents.cancel'))->toBeFalse()
        ->and($resolver->allows($accountant, 'attestations.block'))->toBeTrue()
        ->and($resolver->allows($accountant, 'members.radiation.cancel'))->toBeFalse()
        ->and(UserPermissionOverride::query()->where('membership_id', $accountant->id)->pluck('granted', 'permission')->all())
        ->toEqualCanonicalizing(['members.update' => false, 'documents.cancel' => false, 'attestations.block' => true])
        ->and(UserPermissionOverride::query()->where('permission', 'attestations.block')->value('reason'))->toBe('Stagiaire');

    $cloud = cloudOf($manager, $uri);
    expect($cloud['members.update'])->toMatchArray(['granted' => false, 'profile' => true, 'adjusted' => true, 'origin' => 'user'])
        ->and($cloud['documents.cancel']['profile'])->toBeTrue();
});

it('RG-ROLE-05 applique un droit accordé à un agent dès sa requête suivante', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $accountant = Membership::factory()->create(['organization_id' => $manager->organization_id]);
    $uri = "/api/v1/cga/users/{$accountant->user_id}/permissions";
    inSpace($accountant)->getJson('/api/v1/cga/profile')->assertForbidden();

    inSpace($manager)->putJson($uri, ['permissions' => adjusted(cloudOf($manager, $uri), add: ['cga.profile.view'])])->assertOk();

    inSpace($accountant)->getJson('/api/v1/cga/profile')->assertOk();
    expect(AuditLog::query()->where('event', 'created')->where('subject_type', (new UserPermissionOverride)->getMorphClass())->value('user_id'))->toBe($manager->user_id);
});

it('ajuste tous les rôles de l\'agent et rend son profil au retour aux valeurs par défaut', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $accountant = Membership::factory()->create(['organization_id' => $manager->organization_id]);
    $legal = Membership::factory()->create(['organization_id' => $manager->organization_id, 'user_id' => $accountant->user_id, 'role' => Role::CgaLegal]);
    $uri = "/api/v1/cga/users/{$accountant->user_id}/permissions";

    inSpace($manager)->putJson($uri, ['permissions' => adjusted(cloudOf($manager, $uri), remove: ['members.update'])])->assertOk();
    expect(UserPermissionOverride::query()->whereIn('membership_id', [$accountant->id, $legal->id])->count())->toBe(2)
        ->and(app(PermissionResolver::class)->allows($legal, 'members.update'))->toBeFalse();

    inSpace($manager)->postJson("{$uri}/reset")->assertOk()->assertJsonPath('data.adjusted_count', 0);
    expect(UserPermissionOverride::query()->count())->toBe(0)
        ->and(app(PermissionResolver::class)->allows($accountant, 'members.update'))->toBeTrue();
});

it('réserve le nuage du centre à son gérant, hors gérant et hors de lui-même', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $otherManager = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $manager->organization_id]);
    $accountant = Membership::factory()->create(['organization_id' => $manager->organization_id]);
    $elsewhere = Membership::factory()->create();

    inSpace($manager)->getJson("/api/v1/cga/users/{$otherManager->user_id}/permissions")->assertForbidden();
    inSpace($manager)->putJson("/api/v1/cga/users/{$manager->user_id}/permissions", ['permissions' => []])->assertForbidden();
    inSpace($manager)->getJson("/api/v1/cga/users/{$elsewhere->user_id}/permissions")->assertNotFound();
    inSpace(Membership::factory()->role(Role::CgaAccountantExpert)->create(['organization_id' => $manager->organization_id]))
        ->postJson("/api/v1/cga/users/{$accountant->user_id}/permissions/reset")->assertForbidden();
    inSpace($manager)->putJson("/api/v1/cga/users/{$accountant->user_id}/permissions", [])->assertUnprocessable()->assertJsonValidationErrors('permissions');

    $context = app(TenantContext::class);
    expect(fn () => $context->runAs($manager, fn () => app(SaveUserPermissions::class)->execute($manager->organization, $manager->user, [], null, $manager->user)))->toThrow(BusinessRuleException::class)
        ->and(fn () => $context->runAs($manager, fn () => app(ResetUserPermissions::class)->execute($manager->organization, $manager->user, $manager->user)))->toThrow(BusinessRuleException::class);
});

describe('utilisateurs du CNCGA, par l\'administrateur de la plateforme', function () {
    it('liste les utilisateurs du CNCGA et ajuste leurs droits, contournement journalisé', function () {
        $admin = Membership::factory()->role(Role::PlatformAdmin)->create();
        $networkManager = Membership::factory()->role(Role::CncgaManager)->create();
        $uri = "/api/v1/platform/network-users/{$networkManager->user_id}/permissions";

        inSpace($admin)->getJson('/api/v1/platform/network-users')->assertOk()->assertJsonPath('data.0.id', $networkManager->user_id);
        inSpace($admin)->putJson($uri, ['permissions' => adjusted(cloudOf($admin, $uri), remove: ['organizations.manage'])])
            ->assertOk()->assertJsonPath('data.adjusted_count', 1);

        expect(app(PermissionResolver::class)->allows($networkManager, 'organizations.manage'))->toBeFalse()
            ->and(UserPermissionOverride::query()->withoutGlobalScopes()->sole()->organization_id)->toBe($networkManager->organization_id)
            ->and(AuditLog::query()->where('event', 'tenancy_bypass')->exists())->toBeTrue();

        inSpace($admin)->postJson("{$uri}/reset")->assertOk();
        expect(UserPermissionOverride::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('réserve ces routes à l\'administrateur, et aux seuls utilisateurs du CNCGA', function () {
        $admin = Membership::factory()->role(Role::PlatformAdmin)->create();
        $manager = Membership::factory()->role(Role::CgaManager)->create();
        $networkManager = Membership::factory()->role(Role::CncgaManager)->create();

        inSpace($manager)->getJson('/api/v1/platform/network-users')->assertForbidden();
        inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson("/api/v1/platform/network-users/{$networkManager->user_id}/permissions")->assertForbidden();
        inSpace($admin)->getJson("/api/v1/platform/network-users/{$manager->user_id}/permissions")->assertNotFound();
        inSpace($admin)->getJson("/api/v1/cga/users/{$manager->user_id}/permissions")->assertNotFound();
    });
});

it('donne aux nouveaux profils du centre leurs droits par défaut', function () {
    $catalog = app(PermissionCatalog::class);

    expect($catalog->grantsByDefault(Role::CgaTrainer, 'trainings.cga.manage'))->toBeTrue()
        ->and($catalog->grantsByDefault(Role::CgaBillingOfficer, 'invoices.issue'))->toBeTrue()
        ->and($catalog->grantsByDefault(Role::CgaBillingOfficer, 'members.update'))->toBeFalse()
        ->and($catalog->grantsByDefault(Role::CgaTaxAdvisor, 'obligations.manage'))->toBeTrue()
        ->and(User::factory()->make())->toBeInstanceOf(User::class);
});
