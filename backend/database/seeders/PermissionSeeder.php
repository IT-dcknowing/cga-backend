<?php

namespace Database\Seeders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Permissions\PermissionCatalog;
use App\Domain\Users\Permissions\PermissionResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Matrice des droits par défaut (01-PRD §3.3, RG-ROLE-01) : une ligne réseau par rôle et par droit
 * paramétrable. Les droits verrouillés restent dans le code. Une ligne existante n'est jamais
 * remplacée : un paramétrage du réseau survit à un nouveau passage.
 */
class PermissionSeeder extends Seeder
{
    private const CHUNK = 500;

    public function run(PermissionCatalog $catalog, PermissionResolver $resolver, AuditLogger $audit): void
    {
        $now = now();
        $rows = [];

        foreach ($catalog->codes() as $code) {
            if ($catalog->isLocked($code)) {
                continue;
            }

            foreach (Role::cases() as $role) {
                if ($role === Role::PlatformAdmin) {
                    continue;
                }

                $rows[] = [
                    'organization_id' => null,
                    'role' => $role->value,
                    'permission' => $code,
                    'granted' => $catalog->grantsByDefault($role, $code),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $inserted = 0;
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $inserted += DB::table('role_permissions')->insertOrIgnore($chunk);
        }

        if ($inserted > 0) {
            $audit->record('permissions_seeded', context: ['rows' => $inserted]);
            $resolver->flush();
        }
    }
}
