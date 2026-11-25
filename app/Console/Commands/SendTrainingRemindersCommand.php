<?php

namespace App\Console\Commands;

use App\Domain\Trainings\Actions\SendTrainingReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `reminders:trainings` (PRD M13, rappel J-1) : rappel aux inscrits des sessions qui approchent ; journalisée
 * comme toute commande (SystemActivityListener), une erreur est journalisée puis relancée.
 */
class SendTrainingRemindersCommand extends Command
{
    protected $signature = 'reminders:trainings';

    protected $description = 'Rappel aux inscrits avant une session de formation';

    public function handle(SendTrainingReminders $reminders): int
    {
        try {
            $count = $reminders->execute(CarbonImmutable::now());
        } catch (Throwable $exception) {
            Log::error('Formations : échec des rappels aux inscrits.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info("{$count} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
