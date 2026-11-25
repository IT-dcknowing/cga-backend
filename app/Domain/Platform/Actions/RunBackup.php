<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Notifications\Support\Recipients;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Backups\BackupConnections;
use App\Domain\Platform\Backups\BackupManifest;
use App\Domain\Platform\Backups\BackupTools;
use App\Domain\Platform\Backups\DatabaseSnapshot;
use App\Domain\Platform\Backups\FileStats;
use App\Domain\Platform\Enums\BackupStatus;
use App\Domain\Platform\Models\BackupRun;
use App\Domain\Users\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sauvegarde de nuit (backup:run, 02-TRD §12.3) :
 * 1. la base, par pg_dump au format personnalisé (restaurable par pg_restore), chiffrée, lue dans un instantané
 *    dont les comptes de lignes vont au manifeste ;
 * 2. le stockage des fichiers : un jeu = une archive complète (le jour backup.full_weekday, ou sans jeu en cours)
 *    suivie de ses archives incrémentales (tar --listed-incremental), chiffrées ; nombre et taille des fichiers au
 *    manifeste (`*.manifest.json` à côté de la base, sans donnée nominative), contrôlé par backup:restore-test ;
 * 3. la rétention : une sauvegarde de la base ou un jeu de fichiers dont la dernière archive dépasse
 *    backup.retention_days jours est supprimé (jamais le jeu en cours) ;
 * 4. la copie hors du serveur (backup.remote) ; sans copie configurée, la sauvegarde est faite et une alerte est
 *    journalisée.
 * Chaque passage est enregistré (backup_runs). Un échec est journalisé, signalé aux administrateurs de la plateforme
 * et relancé ; les fichiers inachevés (.partial) sont effacés.
 */
final class RunBackup
{
    private const CURRENT_SET = 'current';

    public function __construct(
        private readonly BackupTools $tools,
        private readonly Recipients $recipients,
        private readonly BackupConnections $connections,
        private readonly DatabaseSnapshot $snapshot,
        private readonly FileStats $fileStats,
    ) {}

    /**
     * @throws Throwable sauvegarde en échec
     */
    public function execute(CarbonImmutable $now): BackupRun
    {
        $run = new BackupRun;
        $run->status = BackupStatus::Running;
        $run->started_at = $now;
        $run->save();

        $directory = rtrim((string) config('backup.directory'), '/\\');
        try {
            if (blank(config('backup.gpg_recipient'))) {
                throw new RuntimeException('Aucune clé de chiffrement (BACKUP_GPG_RECIPIENT).');
            }
            $stamp = $now->format('Ymd-His');
            File::ensureDirectoryExists("{$directory}/db", 0700);
            File::ensureDirectoryExists("{$directory}/files", 0700);

            $database = $this->connections->parameters();
            $databaseFile = "{$directory}/db/{$database['database']}-{$stamp}.dump.gpg";
            $tables = $this->dumpInSnapshot($database, "{$databaseFile}.partial");
            File::move("{$databaseFile}.partial", $databaseFile);

            [$set, $level] = $this->fileSet($directory, $now, $stamp);
            $filesFile = "{$directory}/files/{$set}/{$stamp}-{$level}.tar.gz.gpg";
            $storage = (string) config('filesystems.disks.private.root');
            $before = $this->fileStats->measure($storage);
            $this->tools->archiveFiles($storage, "{$directory}/files/{$set}/snapshot.snar", "{$filesFile}.partial");
            $after = $this->fileStats->measure($storage);
            File::move("{$filesFile}.partial", $filesFile);

            $this->writeManifest(BackupManifest::pathFor($databaseFile), [
                'version' => BackupManifest::VERSION,
                'created_at' => $now->toIso8601String(),
                'database_file' => basename($databaseFile),
                'tables' => $tables,
                'files' => [
                    'set' => $set,
                    'archive' => basename($filesFile),
                    'count_min' => min($before['count'], $after['count']),
                    'count_max' => max($before['count'], $after['count']),
                    'bytes_min' => min($before['bytes'], $after['bytes']),
                    'bytes_max' => max($before['bytes'], $after['bytes']),
                ],
            ]);

            $this->retention($directory, $now, $set);

            $remote = config('backup.remote');
            if (filled($remote)) {
                $this->tools->copyRemote($directory, (string) $remote);
            } else {
                Log::warning('Sauvegarde : aucune copie hors du serveur (BACKUP_REMOTE est vide).');
            }

            $run->status = BackupStatus::Done;
            $run->database_file = basename($databaseFile);
            $run->database_bytes = (int) File::size($databaseFile);
            $run->files_file = "{$set}/".basename($filesFile);
            $run->files_level = $level;
            $run->files_bytes = (int) File::size($filesFile);
            $run->remote_copied = filled($remote);
            $run->finished_at = CarbonImmutable::now();
            $run->save();

            return $run;
        } catch (Throwable $exception) {
            $this->cleanPartials($directory);
            $run->status = BackupStatus::Failed;
            $run->error = mb_substr($exception->getMessage(), 0, (int) config('backup.error_max_length'));
            $run->finished_at = CarbonImmutable::now();
            $run->save();
            Log::error('Sauvegarde en échec.', ['backup_run_id' => $run->id, 'exception' => $exception]);
            $this->alert($run);

            throw $exception;
        }
    }

    /**
     * Jeu de fichiers du jour : nouveau jeu complet le jour de l'archive complète ou sans jeu en cours, sinon archive
     * incrémentale du jeu en cours.
     *
     * @return array{0: string, 1: 'full'|'incremental'}
     */
    private function fileSet(string $directory, CarbonImmutable $now, string $stamp): array
    {
        $pointer = "{$directory}/files/".self::CURRENT_SET;
        $current = File::exists($pointer) ? trim(File::get($pointer)) : '';
        if ($now->dayOfWeekIso !== (int) config('backup.full_weekday') && $current !== '' && File::exists("{$directory}/files/{$current}/snapshot.snar")) {
            return [$current, 'incremental'];
        }
        File::ensureDirectoryExists("{$directory}/files/{$stamp}", 0700);
        File::put($pointer, $stamp);

        return [$stamp, 'full'];
    }

    private function retention(string $directory, CarbonImmutable $now, string $currentSet): void
    {
        $limit = $now->subDays((int) config('backup.retention_days'))->getTimestamp();
        foreach (File::files("{$directory}/db") as $dump) {
            if ($dump->getMTime() < $limit) {
                File::delete($dump->getPathname());
            }
        }
        foreach (File::directories("{$directory}/files") as $set) {
            $latest = max([0, ...array_map(static fn ($file): int => (int) $file->getMTime(), File::files($set))]);
            if (basename($set) !== $currentSet && $latest < $limit) {
                File::deleteDirectory($set);
            }
        }
    }

    private function cleanPartials(string $directory): void
    {
        foreach ([...File::glob("{$directory}/db/*.partial"), ...File::glob("{$directory}/files/*/*.partial")] as $partial) {
            File::delete($partial);
        }
    }

    /**
     * pg_dump lit l'instantané dans lequel les lignes de chaque table ont été comptées ; l'instantané est toujours
     * refermé, même en cas d'échec.
     *
     * @param  array{host: string, port: string, username: string, password: string, database: string}  $database
     * @return array<string, int> nombre de lignes de chaque table
     */
    private function dumpInSnapshot(array $database, string $target): array
    {
        try {
            $snapshot = $this->snapshot->open();
            $this->tools->dumpDatabase([...$database, 'snapshot' => $snapshot['id']], $target);

            return $snapshot['tables'];
        } finally {
            $this->snapshot->release();
        }
    }

    /**
     * Manifeste de la sauvegarde (comptes de lignes et de fichiers), lu par le test de restauration. Il ne contient
     * aucune donnée nominative.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifest(string $path, array $manifest): void
    {
        File::put("{$path}.partial", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        File::move("{$path}.partial", $path);
    }

    /**
     * Administrateurs de la plateforme prévenus d'une sauvegarde en échec.
     */
    private function alert(BackupRun $run): void
    {
        $platform = Organization::query()->where('type', OrganizationType::Platform)->value('id');
        if ($platform !== null) {
            $this->recipients->notify($this->recipients->roles((string) $platform, [Role::PlatformAdmin]), 'backup_failed', [
                'date' => $run->started_at->format((string) config('cga.exports.datetime_format')),
            ]);
        }
    }
}
