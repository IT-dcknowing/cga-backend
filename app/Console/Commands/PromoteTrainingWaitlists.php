<?php

namespace App\Console\Commands;

use App\Domain\Trainings\Actions\PromoteWaitlist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `trainings:waitlist` (02-TRD §11, RG-FOR-03) : promotion des listes d'attente des formations à venir qui
 * ont des places libres ; journalisée comme toute commande (SystemActivityListener), une erreur est
 * journalisée puis relancée.
 */
class PromoteTrainingWaitlists extends Command
{
    protected $signature = 'trainings:waitlist';

    protected $description = 'Promotion des listes d\'attente des formations';

    public function handle(PromoteWaitlist $promote): int
    {
        try {
            $count = $promote->all();
        } catch (Throwable $exception) {
            Log::error('Formations : échec de la promotion des listes d\'attente.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info("{$count} personne(s) promue(s) de la liste d'attente.");

        return self::SUCCESS;
    }
}
