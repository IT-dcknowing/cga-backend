<?php

namespace App\Console\Commands;

use App\Domain\Platform\Actions\VerifyBackupRestore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * `backup:restore-test` (02-TRD §12.3, étape 9.1) : test trimestriel de restauration, sur un serveur isolé qui détient
 * la clé privée GPG (docs/backup-restore.md). Restaure la base dans une base vide et les fichiers dans un dossier de
 * travail, compare au manifeste de la sauvegarde et écrit un rapport. Échoue (code 1) si un contrôle ne passe pas.
 * Journalisée comme toute commande ; une erreur est journalisée par VerifyBackupRestore puis relancée.
 */
class VerifyBackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore-test
        {target : Base PostgreSQL vide qui reçoit la restauration (jamais celle de l’application)}
        {--dump= : Sauvegarde de la base à tester (nom du fichier .dump.gpg ; défaut : la plus récente)}';

    protected $description = 'Teste la restauration d’une sauvegarde sur un serveur isolé';

    public function handle(VerifyBackupRestore $verify): int
    {
        $dump = $this->option('dump');

        try {
            $report = $verify->execute((string) $this->argument('target'), is_string($dump) && $dump !== '' ? $dump : null, CarbonImmutable::now());
        } catch (Throwable $exception) {
            $this->error('Test de restauration en échec : '.$exception->getMessage());

            throw $exception;
        }

        $this->table(['Contrôle', 'Résultat'], [
            ['Sauvegarde', $report['database_file']],
            ['Archives de fichiers', implode(', ', $report['archives'])],
            ['Tables contrôlées', (string) $report['tables']['checked']],
            ['Tables en écart', (string) count($report['tables']['mismatches'])],
            ['Fichiers restaurés', "{$report['files']['restored_count']} (attendu {$report['files']['expected_min']} à {$report['files']['expected_max']})"],
            ['Durée', "{$report['duration_seconds']} s"],
        ]);
        foreach ($report['tables']['mismatches'] as $table => $mismatch) {
            $this->warn("Écart sur {$table} : {$mismatch['expected']} attendu(s), ".($mismatch['restored'] ?? 'table absente').' restauré(s).');
        }

        if ($report['status'] !== 'passed') {
            $this->error("Restauration NON conforme. Rapport : {$report['report_file']}");

            return self::FAILURE;
        }

        $this->info("Restauration réussie. Rapport : {$report['report_file']}");

        return self::SUCCESS;
    }
}
