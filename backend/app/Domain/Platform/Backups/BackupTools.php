<?php

namespace App\Domain\Platform\Backups;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Outils système d'une sauvegarde (02-TRD §12.3), lancés sans interpréteur de commandes (arguments passés un à un,
 * aucune injection possible) : la sortie de pg_dump ou de tar est chiffrée au fil de l'eau par gpg (jamais de copie
 * en clair sur le disque), puis rsync copie le dossier des sauvegardes hors du serveur. Le mot de passe de la base
 * passe par l'environnement de pg_dump (PGPASSWORD), jamais par sa ligne de commande ni par un journal.
 */
class BackupTools
{
    /** Codes de sortie admis de tar : 1 = un fichier a changé pendant la lecture (repris par l'archive suivante). */
    private const TAR_ACCEPTED = [0, 1];

    /**
     * Base exportée par pg_dump ; avec un instantané (DatabaseSnapshot), pg_dump lit exactement l'état compté dans le
     * manifeste.
     *
     * @param  array{host: string, port: string, username: string, password: string, database: string, snapshot?: string}  $database
     *
     * @throws RuntimeException outil en échec
     */
    public function dumpDatabase(array $database, string $target): void
    {
        $this->encrypted([
            ...$this->command('pg_dump'),
            '--format=custom', '--no-owner', '--no-privileges',
            ...(isset($database['snapshot']) ? ['--snapshot='.$database['snapshot']] : []),
            '--host='.$database['host'], '--port='.$database['port'], '--username='.$database['username'],
            $database['database'],
        ], ['PGPASSWORD' => $database['password']], $target, [0]);
    }

    /**
     * Test de restauration : la sauvegarde chiffrée de la base est déchiffrée au fil de l'eau (jamais en clair sur le
     * disque) et lue par pg_restore dans la base de restauration.
     *
     * @param  array{host: string, port: string, username: string, password: string, database: string}  $database
     *
     * @throws RuntimeException outil en échec
     */
    public function restoreDatabase(array $database, string $encryptedDump): void
    {
        $this->decrypted($encryptedDump, [
            ...$this->command('pg_restore'),
            '--no-owner', '--no-privileges',
            '--host='.$database['host'], '--port='.$database['port'], '--username='.$database['username'],
            '--dbname='.$database['database'],
        ], ['PGPASSWORD' => $database['password']]);
    }

    /**
     * Test de restauration : une archive de fichiers (complète ou incrémentale) est déchiffrée au fil de l'eau et
     * extraite dans le dossier ; les archives d'un jeu s'appliquent dans l'ordre (les suppressions sont rejouées).
     *
     * @throws RuntimeException outil en échec
     */
    public function extractFiles(string $encryptedArchive, string $directory): void
    {
        $this->decrypted($encryptedArchive, [
            ...$this->command('tar'),
            '--extract', '--gzip', '--listed-incremental=/dev/null', '--directory='.$directory,
        ], []);
    }

    /**
     * @throws RuntimeException outil en échec
     */
    public function archiveFiles(string $source, string $snapshot, string $target): void
    {
        $this->encrypted([
            ...$this->command('tar'),
            '--create', '--gzip', '--listed-incremental='.$snapshot, '--directory='.$source, '.',
        ], [], $target, self::TAR_ACCEPTED);
    }

    /**
     * @throws RuntimeException copie en échec
     */
    public function copyRemote(string $directory, string $remote): void
    {
        $copy = new Process([
            ...$this->command('rsync'),
            '--archive', '-e', (string) config('backup.ssh_command'),
            rtrim($directory, '/\\').'/', rtrim($remote, '/').'/',
        ], null, null, null, $this->timeout());
        $copy->run();
        if (! $copy->isSuccessful()) {
            throw new RuntimeException('Copie hors du serveur en échec : '.trim($copy->getErrorOutput()));
        }
    }

    /**
     * Sortie de la commande chiffrée par gpg vers le fichier cible.
     *
     * @param  list<string>  $producerCommand
     * @param  array<string, string>  $environment
     * @param  list<int>  $accepted  codes de sortie admis de la commande
     */
    private function encrypted(array $producerCommand, array $environment, string $target, array $accepted): void
    {
        $producer = new Process($producerCommand, null, $environment === [] ? null : $environment, null, $this->timeout());
        $producer->start();

        $encrypt = new Process([
            ...$this->command('gpg'),
            '--batch', '--yes', '--trust-model', 'always', '--encrypt', '--recipient', (string) config('backup.gpg_recipient'),
            '--output', $target,
        ], null, null, $producer->getIterator(Process::ITER_SKIP_ERR), $this->timeout());
        $encrypt->run();
        $producer->wait();

        if (! in_array($producer->getExitCode(), $accepted, true)) {
            throw new RuntimeException(basename($producerCommand[0]).' en échec ('.$producer->getExitCode().') : '.trim($producer->getErrorOutput()));
        }
        if (! $encrypt->isSuccessful()) {
            throw new RuntimeException('Chiffrement en échec : '.trim($encrypt->getErrorOutput()));
        }
    }

    /**
     * Fichier déchiffré par gpg et passé directement à l'entrée de la commande.
     *
     * @param  list<string>  $consumerCommand
     * @param  array<string, string>  $environment
     */
    private function decrypted(string $source, array $consumerCommand, array $environment): void
    {
        $decrypt = new Process([
            ...$this->command('gpg'),
            '--batch', '--decrypt', $source,
        ], null, null, null, $this->timeout());
        $decrypt->start();

        $consumer = new Process($consumerCommand, null, $environment === [] ? null : $environment, $decrypt->getIterator(Process::ITER_SKIP_ERR), $this->timeout());
        $consumer->run();
        $decrypt->wait();

        if (! $decrypt->isSuccessful()) {
            throw new RuntimeException('Déchiffrement en échec : '.trim($decrypt->getErrorOutput()));
        }
        if (! $consumer->isSuccessful()) {
            throw new RuntimeException(basename($consumerCommand[0]).' en échec ('.$consumer->getExitCode().') : '.trim($consumer->getErrorOutput()));
        }
    }

    /**
     * @return list<string>
     */
    private function command(string $tool): array
    {
        return array_values(array_map('strval', (array) config("backup.commands.{$tool}")));
    }

    private function timeout(): float
    {
        return (float) config('backup.timeout_seconds');
    }
}
