<?php

namespace App\Support\Health\Checks;

use App\Domain\Platform\Enums\BackupStatus;
use App\Domain\Platform\Models\BackupRun;
use App\Support\Health\HealthCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthCheckResult;
use Carbon\CarbonImmutable;

/**
 * La dernière sauvegarde réussie date de moins de backup.max_age_hours heures (02-TRD §12.3). Sans objet tant que les
 * sauvegardes sont désactivées (poste de développement).
 */
class BackupCheck implements HealthCheck
{
    public function name(): string
    {
        return 'backup';
    }

    public function run(): HealthCheckResult
    {
        if (! config('backup.enabled')) {
            return HealthCheckResult::ok();
        }
        $last = BackupRun::query()->where('status', BackupStatus::Done)->max('finished_at');
        $limit = CarbonImmutable::now()->subHours((int) config('backup.max_age_hours'));

        if ($last === null || CarbonImmutable::parse((string) $last)->lessThan($limit)) {
            return HealthCheckResult::failed(HealthCheckError::Outdated, [
                'last_backup_at' => $last === null ? null : CarbonImmutable::parse((string) $last)->toIso8601String(),
                'max_age_hours' => (int) config('backup.max_age_hours'),
            ]);
        }

        return HealthCheckResult::ok();
    }
}
