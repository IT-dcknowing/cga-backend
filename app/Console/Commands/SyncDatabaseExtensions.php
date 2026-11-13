<?php

namespace App\Console\Commands;

use App\Support\Database\Extensions;
use Illuminate\Console\Command;

/**
 * Active les extensions PostgreSQL optionnelles que le serveur propose désormais (02-TRD §12.1), par
 * exemple après une montée de version ou un changement d'hébergement. Sans risque : peut être relancée.
 * Redémarrer ensuite les workers (queue:restart), qui mémorisent les extensions présentes.
 */
final class SyncDatabaseExtensions extends Command
{
    protected $signature = 'db:extensions';

    protected $description = 'Active les extensions PostgreSQL optionnelles disponibles (unaccent, pg_trgm) et leurs index';

    public function handle(): int
    {
        $labels = ['installed' => 'installée', 'unavailable' => 'non proposée par le serveur', 'refused' => 'refusée (droits insuffisants)'];

        $rows = [];
        foreach (Extensions::sync() as $name => $status) {
            $rows[] = [$name, $labels[$status]];
        }

        $this->table(['Extension', 'État'], $rows);

        return self::SUCCESS;
    }
}
