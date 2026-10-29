<?php

namespace App\Console\Commands;

use App\Domain\Obligations\Actions\GenerateObligationInstances;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tâche quotidienne des échéances d'obligations (F-OBL-03, RG-OBL-04) : idempotente, journalisée comme
 * toute commande (SystemActivityListener).
 */
class GenerateObligations extends Command
{
    protected $signature = 'obligations:generate';

    protected $description = 'Crée les échéances des obligations confirmées jusqu\'à l\'horizon paramétré';

    public function handle(GenerateObligationInstances $instances): int
    {
        try {
            $created = $instances->execute();
        } catch (Throwable $exception) {
            Log::error('Échéances des obligations : échec de la génération.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info($created.' échéance(s) d\'obligation créée(s).');

        return self::SUCCESS;
    }
}
