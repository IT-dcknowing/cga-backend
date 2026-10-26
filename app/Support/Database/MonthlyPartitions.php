<?php

namespace App\Support\Database;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Partitions mensuelles des journaux (05-BACKEND-SCHEMA §16 : audit_logs et activity_logs partitionnées
 * par mois sur created_at). Chaque table a une partition par défaut, pour qu'une écriture n'échoue
 * jamais, et les partitions du mois en cours et des mois suivants (database.partitions.months_ahead).
 */
final class MonthlyPartitions
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Crée ce qui manque, sans rien toucher à l'existant.
     *
     * @return list<string> les partitions créées
     */
    public function ensure(string $table, CarbonImmutable $from): array
    {
        if (! in_array($table, $this->tables(), true)) {
            throw new InvalidArgumentException("La table {$table} n'est pas déclarée comme partitionnée (database.partitions.tables).");
        }

        $created = [];
        $default = "{$table}_default";
        if (! Schema::hasTable($default)) {
            $this->statement(sprintf('CREATE TABLE %s PARTITION OF %s DEFAULT', $default, $table));
            $created[] = $default;
        }

        $month = $from->utc()->startOfMonth();
        for ($offset = 0; $offset <= $this->config->integer('database.partitions.months_ahead'); $offset++) {
            $start = $month->addMonths($offset);
            $partition = sprintf('%s_%s', $table, $start->format('Y_m'));

            if (! Schema::hasTable($partition)) {
                $this->statement(sprintf(
                    "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                    $partition,
                    $table,
                    $start->format('Y-m-d H:i:sP'),
                    $start->addMonth()->format('Y-m-d H:i:sP'),
                ));
                $created[] = $partition;
            }
        }

        return $created;
    }

    /**
     * @return list<string>
     */
    public function ensureAll(CarbonImmutable $from): array
    {
        $created = [];
        foreach ($this->tables() as $table) {
            array_push($created, ...$this->ensure($table, $from));
        }

        return $created;
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_map(Constraints::identifier(...), $this->config->array('database.partitions.tables'));
    }

    private function statement(string $sql): void
    {
        // @phpstan-ignore cga.rawSqlWithVariable (table déclarée et vérifiée, bornes calculées par le serveur)
        DB::statement($sql);
    }
}
