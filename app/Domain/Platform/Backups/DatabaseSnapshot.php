<?php

namespace App\Domain\Platform\Backups;

use Illuminate\Database\Connection;

/**
 * Instantané PostgreSQL partagé entre le comptage du manifeste et pg_dump (02-TRD §12.3) : une transaction
 * REPEATABLE READ en lecture seule exporte son instantané (pg_export_snapshot), compte les lignes de chaque table,
 * puis reste ouverte pendant que pg_dump lit exactement le même état (--snapshot). Le test de restauration compare
 * donc la base restaurée à des comptes exacts, même si l'application écrit pendant la sauvegarde.
 */
class DatabaseSnapshot
{
    private ?Connection $connection = null;

    public function __construct(private readonly BackupConnections $connections) {}

    /**
     * @return array{id: string, tables: array<string, int>}
     */
    public function open(): array
    {
        $this->release();
        $connection = $this->connections->open(BackupConnections::SNAPSHOT);
        $connection->unprepared('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $this->connection = $connection;

        $snapshot = $connection->selectOne('SELECT pg_export_snapshot() AS id');

        return [
            'id' => (string) $snapshot->id,
            'tables' => $this->connections->rowCounts($connection),
        ];
    }

    /**
     * Ferme la transaction de l'instantané (après pg_dump, ou en cas d'échec).
     */
    public function release(): void
    {
        if ($this->connection === null) {
            return;
        }
        $connection = $this->connection;
        $this->connection = null;
        try {
            $connection->unprepared('COMMIT');
        } finally {
            $this->connections->close(BackupConnections::SNAPSHOT);
        }
    }
}
