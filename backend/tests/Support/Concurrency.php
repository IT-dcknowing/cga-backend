<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Tests de concurrence (étapes 2.3, 2.13) : de vrais processus PHP, lancés ensemble, travaillent sur la
 * base de test du worker. Les données du test y sont donc validées (commit) avant le départ, puis la
 * base est vidée et la transaction du test rouverte, comme si rien ne s'était passé.
 */
final class Concurrency
{
    private const SCRIPT = 'tests/Fixtures/Concurrency/validate-member.php';

    /**
     * Valide en même temps chaque paire [rattachement, dossier] ; rend la sortie de chaque processus
     * (« OK numéro » ou « ERR code »), dans l'ordre des paires.
     *
     * @param  list<array{0: string, 1: string}>  $validations
     * @return list<string>
     */
    public static function validate(array $validations): array
    {
        return self::committed(static function () use ($validations): array {
            $start = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cga-start-'.bin2hex(random_bytes(6));
            $processes = array_map(static function (array $pair) use ($start): Process {
                $process = new Process([PHP_BINARY, base_path(self::SCRIPT), $pair[0], $pair[1], $start], base_path(), self::environment(), null, 120);
                $process->start();

                return $process;
            }, $validations);

            try {
                foreach ($processes as $process) {
                    $process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready'));
                }
                touch($start);

                return array_map(static function (Process $process): string {
                    $process->wait();
                    if (! $process->isSuccessful()) {
                        throw new RuntimeException('Processus de validation en échec : '.$process->getErrorOutput());
                    }

                    return trim(str_replace('ready', '', $process->getOutput()));
                }, $processes);
            } finally {
                if (is_file($start)) {
                    unlink($start);
                }
            }
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function committed(Closure $callback): mixed
    {
        $connection = DB::connection();
        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }

        try {
            return $callback();
        } finally {
            $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'"))
                ->map(static fn (object $row): string => '"'.$row->tablename.'"')
                ->implode(', ');
            // @phpstan-ignore cga.rawSqlWithVariable (noms de tables lus dans le catalogue de PostgreSQL)
            DB::statement("TRUNCATE TABLE {$tables} RESTART IDENTITY CASCADE");
            $connection->beginTransaction();
        }
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $database = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SEED_DEMO' => 'false',
        ];
    }
}
