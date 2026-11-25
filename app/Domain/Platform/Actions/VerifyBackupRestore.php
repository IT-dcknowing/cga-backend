<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Backups\BackupConnections;
use App\Domain\Platform\Backups\BackupManifest;
use App\Domain\Platform\Backups\BackupTools;
use App\Domain\Platform\Backups\FileStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Test de restauration d'une sauvegarde (backup:restore-test, 02-TRD §12.3, étape 9.1), sur un serveur isolé qui
 * détient la clé privée GPG :
 * 1. refus hors des environnements autorisés (backup.restore.environments, jamais la production), refus d'une base
 *    cible qui serait celle de l'application ou qui n'est pas vide ;
 * 2. restauration de la base (gpg → pg_restore, sans copie en clair) puis comparaison, table par table, avec les
 *    comptes du manifeste ;
 * 3. restauration du jeu de fichiers (archive complète puis incrémentales jusqu'à celle de la sauvegarde) dans un
 *    dossier de travail, contrôle du nombre et de la taille des fichiers, puis effacement du dossier ;
 * 4. rapport JSON (backup.restore.report_directory) à reporter au registre d'exploitation.
 * Une erreur est journalisée puis relancée ; le dossier de travail est toujours effacé.
 */
final class VerifyBackupRestore
{
    private const DATABASE_NAME = '/^[A-Za-z0-9_]{1,63}$/';

    public function __construct(
        private readonly BackupTools $tools,
        private readonly BackupConnections $connections,
        private readonly FileStats $fileStats,
    ) {}

    /**
     * @return array{
     *     status: 'passed'|'failed',
     *     tested_at: string,
     *     environment: string,
     *     target_database: string,
     *     database_file: string,
     *     files_set: string,
     *     archives: list<string>,
     *     duration_seconds: float,
     *     tables: array{checked: int, mismatches: array<string, array{expected: int, restored: int|null}>},
     *     files: array{expected_min: int, expected_max: int, restored_count: int, expected_bytes_min: int, expected_bytes_max: int, restored_bytes: int, ok: bool},
     *     report_file: string
     * }
     *
     * @throws Throwable restauration impossible
     */
    public function execute(string $target, ?string $dumpFile, CarbonImmutable $now): array
    {
        $started = microtime(true);
        $directory = rtrim((string) config('backup.directory'), '/\\');
        $workDirectory = rtrim((string) config('backup.restore.work_directory'), '/\\').'/'.$now->format('Ymd-His');

        try {
            $this->guard($target);
            $manifestPath = $dumpFile === null
                ? BackupManifest::latest("{$directory}/db")
                : BackupManifest::pathFor("{$directory}/db/".basename($dumpFile));
            $manifest = BackupManifest::read($manifestPath);
            $databaseFile = "{$directory}/db/{$manifest['database_file']}";
            if (! File::exists($databaseFile)) {
                throw new RuntimeException('Sauvegarde de la base introuvable : '.$manifest['database_file'].'.');
            }
            $archives = $this->archives("{$directory}/files/{$manifest['files']['set']}", $manifest['files']['archive']);

            $this->tools->restoreDatabase($this->connections->parameters($target), $databaseFile);
            $mismatches = $this->compareTables($target, $manifest['tables']);

            File::ensureDirectoryExists($workDirectory, 0700);
            foreach ($archives as $archive) {
                $this->tools->extractFiles($archive, $workDirectory);
            }
            $restored = $this->fileStats->measure($workDirectory);
            $expected = $manifest['files'];
            $filesOk = $restored['count'] >= $expected['count_min'] && $restored['count'] <= $expected['count_max']
                && $restored['bytes'] >= $expected['bytes_min'] && $restored['bytes'] <= $expected['bytes_max'];

            $report = [
                'status' => $mismatches === [] && $filesOk ? 'passed' : 'failed',
                'tested_at' => $now->toIso8601String(),
                'environment' => (string) app()->environment(),
                'target_database' => $target,
                'database_file' => $manifest['database_file'],
                'files_set' => $manifest['files']['set'],
                'archives' => array_map('basename', $archives),
                'duration_seconds' => round(microtime(true) - $started, 1),
                'tables' => ['checked' => count($manifest['tables']), 'mismatches' => $mismatches],
                'files' => [
                    'expected_min' => $expected['count_min'],
                    'expected_max' => $expected['count_max'],
                    'restored_count' => $restored['count'],
                    'expected_bytes_min' => $expected['bytes_min'],
                    'expected_bytes_max' => $expected['bytes_max'],
                    'restored_bytes' => $restored['bytes'],
                    'ok' => $filesOk,
                ],
            ];
            $report['report_file'] = $this->writeReport($report, $now);

            Log::log($report['status'] === 'passed' ? 'info' : 'error', 'Test de restauration terminé.', [
                'status' => $report['status'],
                'database_file' => $report['database_file'],
                'table_mismatches' => count($mismatches),
                'files_ok' => $filesOk,
            ]);

            return $report;
        } catch (Throwable $exception) {
            Log::error('Test de restauration en échec.', ['target_database' => $target, 'exception' => $exception]);

            throw $exception;
        } finally {
            $this->connections->close(BackupConnections::RESTORE);
            File::deleteDirectory($workDirectory);
        }
    }

    /**
     * @throws RuntimeException environnement interdit ou base cible inutilisable
     */
    private function guard(string $target): void
    {
        $environment = (string) app()->environment();
        if (! in_array($environment, (array) config('backup.restore.environments'), true)) {
            throw new RuntimeException("Test de restauration interdit dans l’environnement « {$environment} » : utiliser un serveur isolé.");
        }
        if (preg_match(self::DATABASE_NAME, $target) !== 1) {
            throw new RuntimeException('Nom de base de restauration invalide.');
        }
        if ($target === $this->connections->parameters()['database']) {
            throw new RuntimeException('La base de restauration ne peut pas être celle de l’application.');
        }
        if ($this->connections->tables($this->connections->open(BackupConnections::RESTORE, $target)) !== []) {
            throw new RuntimeException("La base de restauration « {$target} » doit être vide.");
        }
    }

    /**
     * Archives du jeu, dans l'ordre (complète puis incrémentales), jusqu'à celle de la sauvegarde testée.
     *
     * @return list<string>
     *
     * @throws RuntimeException jeu incomplet
     */
    private function archives(string $setDirectory, string $lastArchive): array
    {
        $archives = array_values(array_filter(
            File::glob("{$setDirectory}/*.tar.gz.gpg"),
            static fn (string $archive): bool => strcmp(basename($archive), $lastArchive) <= 0,
        ));
        sort($archives);

        if ($archives === [] || basename((string) end($archives)) !== $lastArchive || ! str_ends_with(basename($archives[0]), '-full.tar.gz.gpg')) {
            throw new RuntimeException('Jeu de fichiers incomplet : '.basename($setDirectory).'.');
        }

        return $archives;
    }

    /**
     * @param  array<string, int>  $expected
     * @return array<string, array{expected: int, restored: int|null}>
     */
    private function compareTables(string $target, array $expected): array
    {
        $restored = $this->connections->rowCounts($this->connections->open(BackupConnections::RESTORE, $target));

        $mismatches = [];
        foreach ($expected as $table => $count) {
            $actual = $restored[$table] ?? null;
            if ($actual !== (int) $count) {
                $mismatches[$table] = ['expected' => (int) $count, 'restored' => $actual];
            }
        }

        return $mismatches;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(array $report, CarbonImmutable $now): string
    {
        $directory = rtrim((string) config('backup.restore.report_directory'), '/\\');
        File::ensureDirectoryExists($directory, 0700);
        $path = "{$directory}/restore-test-{$now->format('Ymd-His')}.json";
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
    }
}
