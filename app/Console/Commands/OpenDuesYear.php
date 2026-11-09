<?php

namespace App\Console\Commands;

use App\Domain\Dues\Actions\OpenDuesYear as OpenYear;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Appel de cotisation de l'année (02-TRD §11, F-COT-01) : le 1er janvier, une cotisation par CGA du
 * registre ; rejouable ; journalisée comme toute commande (SystemActivityListener).
 */
class OpenDuesYear extends Command
{
    protected $signature = 'dues:open-year {--year= : année de la cotisation (par défaut, l\'année en cours)}';

    protected $description = 'Crée la cotisation de l\'année de chaque CGA du registre';

    public function handle(OpenYear $open): int
    {
        $year = $this->option('year');
        if ($year !== null && (! is_string($year) || preg_match('/^\d{4}$/', $year) !== 1)) {
            $this->error('Année invalide : quatre chiffres attendus.');

            return self::INVALID;
        }

        try {
            $created = $open->execute($year === null ? CarbonImmutable::now()->year : (int) $year);
        } catch (Throwable $exception) {
            Log::error('Cotisations : échec de l\'appel de l\'année.', ['exception' => $exception]);

            throw $exception;
        }
        $this->info("{$created} cotisation(s) créée(s).");

        return self::SUCCESS;
    }
}
