<?php

namespace App\Console\Commands;

use App\Support\Database\MonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prépare les partitions mensuelles des journaux à l'avance (tâche planifiée chaque jour, idempotente).
 */
class EnsureLogPartitions extends Command
{
    protected $signature = 'logs:partitions';

    protected $description = 'Crée les partitions mensuelles des journaux pour le mois en cours et les mois suivants';

    public function handle(MonthlyPartitions $partitions): int
    {
        try {
            $created = $partitions->ensureAll(CarbonImmutable::now());
        } catch (Throwable $exception) {
            Log::error('Partitions des journaux : échec de la création.', ['exception' => $exception]);

            throw $exception;
        }

        foreach ($created as $partition) {
            $this->line("Partition créée : {$partition}");
        }

        $this->info($created === [] ? 'Toutes les partitions existent déjà.' : count($created).' partition(s) créée(s).');

        return self::SUCCESS;
    }
}
