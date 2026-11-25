<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\RolePermission;
use App\Domain\Users\Permissions\PermissionCatalog;
use Database\Seeders\PermissionSeeder;

/*
 * Matrice des droits par défaut chargée en base (RG-ROLE-01, étape 0.7).
 */

it('charge une ligne réseau par rôle et par droit paramétrable, sans les droits verrouillés', function () {
    $this->seed(PermissionSeeder::class);

    $catalog = app(PermissionCatalog::class);
    $writable = array_filter($catalog->codes(), fn (string $code): bool => ! $catalog->isLocked($code));
    $roles = count(Role::cases()) - 1;

    expect(RolePermission::query()->count())->toBe(count($writable) * $roles)
        ->and(RolePermission::query()->whereNotNull('organization_id')->exists())->toBeFalse()
        ->and(RolePermission::query()->where('role', Role::PlatformAdmin)->exists())->toBeFalse()
        ->and(RolePermission::query()->where('permission', 'members.radiation.cancel')->exists())->toBeFalse();
});

it('reprend la matrice du catalogue', function () {
    $this->seed(PermissionSeeder::class);

    $row = fn (Role $role, string $permission): bool => RolePermission::query()->whereNull('organization_id')->where('role', $role)->where('permission', $permission)->sole()->granted;

    expect($row(Role::CgaSecretary, 'members.create'))->toBeTrue()
        ->and($row(Role::CgaSecretary, 'members.update'))->toBeFalse()
        ->and($row(Role::CncgaViewer, 'organizations.view'))->toBeTrue();
});

it('peut être rejoué sans remplacer un paramétrage du réseau', function () {
    $this->seed(PermissionSeeder::class);
    $rule = RolePermission::query()->whereNull('organization_id')->where('role', Role::CgaSecretary)->where('permission', 'members.update')->sole();
    $rule->granted = true;
    $rule->save();

    $this->seed(PermissionSeeder::class);

    expect($rule->fresh()->granted)->toBeTrue()
        ->and(AuditLog::query()->where('event', 'permissions_seeded')->count())->toBe(1);
});
