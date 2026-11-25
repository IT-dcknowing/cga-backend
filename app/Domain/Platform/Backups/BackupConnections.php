<?php

namespace App\Domain\Platform\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Connexions dédiées des sauvegardes (02-TRD §12.3) : copies de la connexion par défaut, éventuellement vers une autre
 * base (base de restauration), ouvertes à part pour ne jamais partager la transaction de l'application. Les noms de
 * tables et de bases viennent du catalogue PostgreSQL ou de la configuration, jamais d'une saisie.
 */
class BackupConnections
{
    public const SNAPSHOT = 'backup_snapshot';

    public const RESTORE = 'backup_restore';

    /**
     * Paramètres de la connexion par défaut (hôte, port, utilisateur, mot de passe, base).
     *
     * @return array{host: string, port: string, username: string, password: string, database: string}
     */
    public function parameters(?string $database = null): array
    {
        $connection = (array) config('database.connections.'.config('database.default'));

        return [
            'host' => (string) ($connection['host'] ?? ''),
            'port' => (string) ($connection['port'] ?? ''),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'database' => $database ?? (string) ($connection['database'] ?? ''),
        ];
    }

    public function open(string $name, ?string $database = null): Connection
    {
        $connection = (array) config('database.connections.'.config('database.default'));
        if ($database !== null) {
            $connection['database'] = $database;
        }
        config(["database.connections.{$name}" => $connection]);
        DB::purge($name);

        /** @var Connection */
        return DB::connection($name);
    }

    public function close(string $name): void
    {
        DB::purge($name);
    }

    /**
     * Tables du schéma public, par ordre alphabétique.
     *
     * @return list<string>
     */
    public function tables(Connection $connection): array
    {
        return $connection->table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')
            ->orderBy('table_name')
            ->pluck('table_name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }

    /**
     * Nombre de lignes de chaque table du schéma public.
     *
     * @return array<string, int>
     */
    public function rowCounts(Connection $connection): array
    {
        $counts = [];
        foreach ($this->tables($connection) as $table) {
            $counts[$table] = $connection->table($table)->count();
        }

        return $counts;
    }
}
