<?php

namespace App\Console\Commands;

use App\Domain\Platform\Actions\RunBackup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * `backup:run` (02-TRD §11 et §12.3, chaque nuit) : sauvegarde chiffrée de la base et des fichiers, rétention, copie
 * hors du serveur. Sans effet tant que backup.enabled est faux (poste de développement). Journalisée comme toute
 * commande (SystemActivityListener) ; une erreur est journalisée et signalée par RunBackup, puis relancée.
 */
class RunBackupCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Sauvegarde chiffrée de la base et des fichiers';

    public function handle(RunBackup $backup): int
    {
        if (! config('backup.enabled')) {
            $this->warn('Sauvegardes désactivées (BACKUP_ENABLED).');

            return self::SUCCESS;
        }

        try {
            $run = $backup->execute(CarbonImmutable::now());
        } catch (Throwable $exception) {
            $this->error('Sauvegarde en échec : '.$exception->getMessage());

            throw $exception;
        }

        $this->info("Sauvegarde faite : {$run->database_file}, {$run->files_file}".($run->remote_copied ? ', copiée hors du serveur.' : ', sans copie hors du serveur.'));

        return self::SUCCESS;
    }
}
