<?php

namespace App\Support\Health\Checks;

use App\Support\Config\SettingsService;
use App\Support\Health\HealthCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthCheckResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Le disque de stockage accepte l'écriture et la relecture d'un fichier, et son occupation reste
 * sous le seuil files.disk_alert_percent (02-TRD §6.4).
 */
class StorageCheck implements HealthCheck
{
    private const PROBE_DIRECTORY = 'health';

    public function __construct(private readonly SettingsService $settings) {}

    public function name(): string
    {
        return 'storage';
    }

    public function run(): HealthCheckResult
    {
        $diskName = Config::string('filesystems.default');

        if (! $this->canWriteAndRead()) {
            return HealthCheckResult::failed(HealthCheckError::NotWritable, ['disk' => $diskName]);
        }

        $usedPercent = $this->usedPercent($diskName);
        $alertPercent = $this->alertPercent();

        if ($usedPercent !== null && $usedPercent > $alertPercent) {
            return HealthCheckResult::failed(HealthCheckError::LowSpace, [
                'disk' => $diskName,
                'used_percent' => round($usedPercent, 1),
                'alert_percent' => $alertPercent,
            ]);
        }

        return HealthCheckResult::ok();
    }

    /**
     * Seuil enregistré, ou valeur par défaut du registre si la base ne répond pas : le contrôle du
     * disque ne dépend pas de la base, contrôlée à part.
     */
    private function alertPercent(): int
    {
        try {
            return (int) $this->settings->get('files.disk_alert_percent');
        } catch (QueryException) {
            return (int) $this->settings->default('files.disk_alert_percent');
        }
    }

    private function canWriteAndRead(): bool
    {
        $disk = Storage::disk();
        $path = self::PROBE_DIRECTORY.'/'.Str::ulid().'.txt';
        $content = Str::random(32);

        try {
            return $disk->put($path, $content) && $disk->get($path) === $content;
        } finally {
            $disk->delete($path);
        }
    }

    /**
     * Occupation en pourcentage du disque local ; null pour un stockage sans racine locale (S3).
     */
    private function usedPercent(string $diskName): ?float
    {
        $root = Config::get("filesystems.disks.{$diskName}.root");

        if (! is_string($root)) {
            return null;
        }

        if (! is_dir($root)) {
            throw new RuntimeException("Le dossier du disque « {$diskName} » est introuvable.");
        }

        $total = disk_total_space($root);
        $free = disk_free_space($root);

        if ($total === false || $free === false || $total <= 0) {
            throw new RuntimeException("Impossible de lire l'espace du disque « {$diskName} ».");
        }

        return ($total - $free) / $total * 100;
    }
}
