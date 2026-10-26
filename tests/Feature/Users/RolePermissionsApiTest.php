<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\RolePermission;
use App\Domain\Users\Permissions\PermissionCatalog;
use App\Domain\Users\Permissions\PermissionResolver;

/*
 * Droits par rôle (06-IMPLEMENTATION-PLAN étape 1.8, F-ADM-05, RG-ROLE-01 à 06) : une interface par
 * rôle, au réseau (administrateur de la plateforme) et dans chaque CGA (gérant).
 */

function platformAdmin(): Membership
{
    return Membership::factory()->role(Role::PlatformAdmin)->create();
}

/**
 * Droits accordés d'un rôle tels que l'interface les montre.
 *
 * @return array<string, bool>
 */
function shownRights(Membership $actor, string $level, Role $role): array
{
    $modules = inSpace($actor)->getJson("/api/v1/{$level}/roles/{$role->value}/permissions")->assertOk()->json('data.modules');

    return collect($modules)->flatMap(fn (array $module) => collect($module['permissions'])->mapWithKeys(fn (array $p) => [$p['code'] => $p['granted']]))->all();
}

it('expose le catalogue des droits, rangés par module, à ceux qui paramètrent des droits', function () {
    $response = inSpace(platformAdmin())->getJson('/api/v1/permissions/catalog')->assertOk();

    expect($response->json('data.permissions'))->toHaveCount(count(app(PermissionCatalog::class)->codes()))
        ->and(collect($response->json('data.permissions'))->firstWhere('code', 'members.radiation.cancel'))->toMatchArray(['module' => 'members', 'locked' => true, 'default_roles' => ['cga_manager']])
        ->and($response->json('data.modules.0'))->toBe(['module' => 'network', 'label' => 'Réseau et registre des CGA']);
    inSpace(Membership::factory()->role(Role::CgaManager)->create())->getJson('/api/v1/permissions/catalog')->assertOk();
    inSpace(Membership::factory()->create())->getJson('/api/v1/permissions/catalog')->assertForbidden();
});

it('range chaque droit du catalogue dans un module de l\'écran', function () {
    $catalog = app(PermissionCatalog::class);

    foreach ($catalog->codes() as $code) {
        expect(array_keys($catalog->screenModules()))->toContain($catalog->screenModule($code));
    }
});

it('RG-ROLE-02 liste au réseau tous les rôles sauf celui de l\'administrateur, avec leurs compteurs', function () {
    Membership::factory()->count(2)->create();

    $roles = collect(inSpace(platformAdmin())->getJson('/api/v1/network/roles')->assertOk()->json('data'))->keyBy('role');

    expect($roles->keys()->all())->not->toContain('platform_admin')->toContain('cga_manager', 'cncga_president', 'member_owner')
        ->and($roles['cga_accountant']['users_count'])->toBe(2)
        ->and($roles['cga_accountant']['customized'])->toBeFalse()
        ->and($roles['cga_accountant']['granted_count'])->toBeGreaterThan(0);
});

it('RG-ROLE-03 liste pour un CGA les rôles de son centre, hors gérant', function () {
    $roles = array_column(inSpace(Membership::factory()->role(Role::CgaManager)->create())->getJson('/api/v1/cga/roles')->assertOk()->json('data'), 'role');

    expect($roles)->toContain('cga_accountant', 'cga_secretary', 'cga_trainer')
        ->not->toContain('cga_manager', 'cncga_manager', 'member_owner', 'platform_admin');
});

it('montre les droits d\'un rôle groupés par module, verrous compris, et ses utilisateurs dans un CGA', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $accountant = Membership::factory()->create(['organization_id' => $manager->organization_id]);

    $data = inSpace($manager)->getJson('/api/v1/cga/roles/cga_accountant/permissions')->assertOk()->json('data');
    $rights = collect($data['modules'])->flatMap(fn (array $module) => $module['permissions'])->keyBy('code');

    expect($rights['members.view'])->toMatchArray(['granted' => true, 'reference' => true, 'locked' => false, 'customized' => false])
        ->and($rights['members.radiation.cancel'])->toMatchArray(['granted' => false, 'locked' => true])
        ->and($rights->has('organizations.suspend'))->toBeFalse()
        ->and($rights->has('member.banking.consent'))->toBeFalse()
        ->and($data['users'])->toBe([['id' => $accountant->user_id, 'name' => $accountant->user->fullName(), 'status' => 'active']]);
    expect(inSpace(platformAdmin())->getJson('/api/v1/network/roles/cga_accountant/permissions')->json('data.users'))->toBeNull();
});

it('RG-ROLE-02 écrit les droits d\'un rôle au réseau : verrous et codes inconnus ignorés, journalisé, effet immédiat', function () {
    $admin = platformAdmin();
    $accountant = Membership::factory()->create();
    $resolver = app(PermissionResolver::class);
    expect($resolver->allows($accountant, 'attestations.block'))->toBeFalse();

    $granted = collect(shownRights($admin, 'network', Role::CgaAccountant))->filter()->keys()
        ->reject(fn (string $code) => $code === 'members.create')
        ->push('attestations.block', 'members.radiation.cancel', 'code.inconnu')
        ->values()->all();
    inSpace($admin)->putJson('/api/v1/network/roles/cga_accountant/permissions', ['permissions' => $granted])->assertOk();

    $rights = shownRights($admin, 'network', Role::CgaAccountant);
    expect($rights['attestations.block'])->toBeTrue()
        ->and($rights['members.create'])->toBeFalse()
        ->and($rights['members.radiation.cancel'])->toBeFalse()
        ->and($resolver->allows($accountant, 'attestations.block'))->toBeTrue()
        ->and($resolver->allows($accountant, 'members.create'))->toBeFalse()
        ->and(AuditLog::query()->where('subject_type', (new RolePermission)->getMorphClass())->where('user_id', $admin->user_id)->exists())->toBeTrue();
});

it('RG-ROLE-03 donne la priorité à la surcharge du CGA, pour ce seul centre, sans toucher au réseau', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $here = Membership::factory()->create(['organization_id' => $manager->organization_id]);
    $elsewhere = Membership::factory()->create();

    $granted = collect(shownRights($manager, 'cga', Role::CgaAccountant))->filter()->keys()->reject(fn (string $code) => $code === 'members.update')->values()->all();
    inSpace($manager)->putJson('/api/v1/cga/roles/cga_accountant/permissions', ['permissions' => $granted])
        ->assertOk()
        ->assertJsonPath('data.level', 'cga');

    $resolver = app(PermissionResolver::class);
    expect($resolver->allows($here, 'members.update'))->toBeFalse()
        ->and($resolver->allows($elsewhere, 'members.update'))->toBeTrue()
        ->and(RolePermission::query()->where('organization_id', $manager->organization_id)->pluck('permission')->all())->toBe(['members.update'])
        ->and(RolePermission::query()->whereNull('organization_id')->count())->toBe(0);
    expect(collect(inSpace($manager)->getJson('/api/v1/cga/roles')->json('data'))->firstWhere('role', 'cga_accountant')['customized'])->toBeTrue();
});

it('RG-ROLE-05 applique un droit accordé dès la requête suivante', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $accountant = Membership::factory()->create(['organization_id' => $manager->organization_id]);
    inSpace($accountant)->getJson('/api/v1/cga/profile')->assertForbidden();

    $granted = [...array_keys(array_filter(shownRights($manager, 'cga', Role::CgaAccountant))), 'cga.profile.view'];
    inSpace($manager)->putJson('/api/v1/cga/roles/cga_accountant/permissions', ['permissions' => $granted])->assertOk();

    inSpace($accountant)->getJson('/api/v1/cga/profile')->assertOk();
});

it('RG-ROLE-06 revient aux droits par défaut : au réseau la matrice, pour un CGA le réseau', function () {
    $admin = platformAdmin();
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    inSpace($admin)->putJson('/api/v1/network/roles/cga_secretary/permissions', ['permissions' => ['members.view']])->assertOk();
    inSpace($manager)->putJson('/api/v1/cga/roles/cga_secretary/permissions', ['permissions' => ['members.view', 'members.create']])->assertOk();

    inSpace($manager)->postJson('/api/v1/cga/roles/cga_secretary/permissions/reset')->assertOk();
    expect(RolePermission::query()->whereNotNull('organization_id')->count())->toBe(0)
        ->and(shownRights($manager, 'cga', Role::CgaSecretary)['members.create'])->toBeFalse();

    inSpace($admin)->postJson('/api/v1/network/roles/cga_secretary/permissions/reset')->assertOk();
    $catalog = app(PermissionCatalog::class);
    foreach (shownRights($admin, 'network', Role::CgaSecretary) as $code => $granted) {
        expect($granted)->toBe($catalog->grantsByDefault(Role::CgaSecretary, $code), $code);
    }
});

it('annonce l\'effet d\'un changement avant de l\'enregistrer', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    Membership::factory()->count(2)->create(['organization_id' => $manager->organization_id]);
    $current = array_keys(array_filter(shownRights($manager, 'cga', Role::CgaAccountant)));
    $wanted = [...array_values(array_diff($current, ['members.update'])), 'cga.profile.view', 'members.radiation.cancel'];

    $query = http_build_query(['permissions' => $wanted]);
    inSpace($manager)->getJson("/api/v1/cga/roles/cga_accountant/impact?{$query}")
        ->assertOk()
        ->assertJsonPath('data.users_count', 2)
        ->assertJsonPath('data.added', [['code' => 'cga.profile.view', 'label' => __('permissions.cga.profile.view')]])
        ->assertJsonPath('data.removed', [['code' => 'members.update', 'label' => __('permissions.members.update')]]);
    expect(RolePermission::query()->count())->toBe(0);
});

it('RG-ROLE-04 réserve le paramétrage à l\'administrateur et au gérant, chacun à son niveau et ses rôles', function () {
    $admin = platformAdmin();
    $manager = Membership::factory()->role(Role::CgaManager)->create();

    inSpace($manager)->getJson('/api/v1/cga/roles/cga_manager/permissions')->assertForbidden();
    inSpace($manager)->putJson('/api/v1/cga/roles/cga_manager/permissions', ['permissions' => []])->assertForbidden();
    inSpace($manager)->putJson('/api/v1/cga/roles/cncga_manager/permissions', ['permissions' => []])->assertForbidden();
    inSpace($manager)->getJson('/api/v1/network/roles')->assertForbidden();
    inSpace($admin)->getJson('/api/v1/cga/roles')->assertForbidden();
    inSpace($admin)->getJson('/api/v1/network/roles/platform_admin/permissions')->assertForbidden();
    inSpace($admin)->getJson('/api/v1/network/roles/cga_manager/permissions')->assertOk();
    inSpace($admin)->getJson('/api/v1/network/roles/inconnu/permissions')->assertNotFound();
    inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson('/api/v1/network/roles')->assertForbidden();
    inSpace(Membership::factory()->create(['organization_id' => $manager->organization_id]))->postJson('/api/v1/cga/roles/cga_accountant/permissions/reset')->assertForbidden();
    inSpace($admin)->putJson('/api/v1/network/roles/cga_accountant/permissions', [])->assertUnprocessable()->assertJsonValidationErrors('permissions');
});

it('ne touche pas aux droits d\'un autre CGA', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $other = Organization::factory()->create();

    inSpace($manager)->putJson('/api/v1/cga/roles/cga_accountant/permissions', ['permissions' => []])->assertOk();

    expect(RolePermission::query()->where('organization_id', $other->id)->exists())->toBeFalse()
        ->and(RolePermission::query()->pluck('organization_id')->unique()->all())->toBe([$manager->organization_id]);
});
