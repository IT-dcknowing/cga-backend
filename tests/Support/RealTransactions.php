<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Hors de la transaction du test, comme en production : les données du test sont validées (commit),
 * le code s'exécute dans ses propres transactions, puis la base est vidée et la transaction du test
 * rouverte, comme si rien ne s'était passé. Pour les tests de concurrence (de vrais processus lisent
 * la base) et de durée (des milliers de sous-transactions dans celle du test ralentiraient PostgreSQL
 * bien au-delà de la production).
 */
final class RealTransactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
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
}
