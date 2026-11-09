<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Models\PlatformAccessLog;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Journalise un accès technique de maintenance platform_admin aux données d'un CGA (RG-SEC-04).
 */
class RecordPlatformAccess
{
    public function execute(
        User $adminUser,
        Organization $organization,
        string $reason,
        ?string $route = null,
        ?CarbonImmutable $startedAt = null,
        ?CarbonImmutable $endedAt = null,
    ): PlatformAccessLog {
        return DB::transaction(function () use ($adminUser, $organization, $reason, $route, $startedAt, $endedAt): PlatformAccessLog {
            $log = new PlatformAccessLog([
                'admin_user_id' => $adminUser->id,
                'organization_id' => $organization->id,
                'reason' => trim($reason),
                'route' => $route,
                'started_at' => $startedAt ?? now()->toImmutable(),
                'ended_at' => $endedAt,
            ]);

            $log->save();

            return $log;
        });
    }
}

