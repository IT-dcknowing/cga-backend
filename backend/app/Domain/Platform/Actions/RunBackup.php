<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Notifications\Support\Recipients;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Backups\BackupTools;
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
 * 1. la base, par pg_dump au format personnalisé (restaurable par pg_restore), chiffrée ;
 * 2. le stockage des fichiers : un jeu = une archive complète (le jour backup.full_weekday, ou sans jeu en cours)
 *    suivie de ses archives incrémentales (tar --listed-incremental), chiffrées ;
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

            $connection = (array) config('database.connections.'.config('database.default'));
            $databaseFile = "{$directory}/db/{$connection['database']}-{$stamp}.dump.gpg";
            $this->tools->dumpDatabase([
                'host' => (string) ($connection['host'] ?? ''),
                'port' => (string) ($connection['port'] ?? ''),
                'username' => (string) ($connection['username'] ?? ''),
                'password' => (string) ($connection['password'] ?? ''),
                'database' => (string) $connection['database'],
            ], "{$databaseFile}.partial");
            File::move("{$databaseFile}.partial", $databaseFile);

            [$set, $level] = $this->fileSet($directory, $now, $stamp);
            $filesFile = "{$directory}/files/{$set}/{$stamp}-{$level}.tar.gz.gpg";
            $this->tools->archiveFiles((string) config('filesystems.disks.private.root'), "{$directory}/files/{$set}/snapshot.snar", "{$filesFile}.partial");
            File::move("{$filesFile}.partial", $filesFile);

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
