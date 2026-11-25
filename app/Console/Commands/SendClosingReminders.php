<?php

namespace App\Console\Commands;

use App\Domain\Closing\Actions\SendClosingReminders as SendReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rappels quotidiens de la campagne de clôture (02-TRD §11, RG-OBL-11) ; journalisée comme toute
 * commande (SystemActivityListener).
 */
class SendClosingReminders extends Command
{
    protected $signature = 'reminders:closing';

    protected $description = 'Rappels de pièces aux clients et retards d\'étape aux agents de la campagne de clôture';

    public function handle(SendReminders $reminders): int
    {
        try {
            $result = $reminders->execute(CarbonImmutable::today());
        } catch (Throwable $exception) {
            Log::error('Campagne de clôture : échec des rappels.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info("{$result['pieces']} rappel(s) de pièces, {$result['late']} dossier(s) en retard signalé(s).");

        return self::SUCCESS;
    }
}
