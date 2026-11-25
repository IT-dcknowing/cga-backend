<?php

namespace App\Console\Commands;

use App\Domain\Concerns\Actions\SendStaleConcernAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `reminders:concerns` (02-TRD §11, RG-PRE-02) : alerte interne au CGA des demandes d'adhérents sans réponse
 * depuis concerns.stale_days jours ; journalisée comme toute commande (SystemActivityListener), une erreur
 * est journalisée puis relancée.
 */
class SendConcernReminders extends Command
{
    protected $signature = 'reminders:concerns';

    protected $description = 'Alerte aux CGA des préoccupations d\'adhérents sans réponse';

    public function handle(SendStaleConcernAlerts $alerts): int
    {
        try {
            $count = $alerts->execute(CarbonImmutable::now());
        } catch (Throwable $exception) {
            Log::error('Préoccupations : échec des alertes de demandes sans réponse.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info("{$count} demande(s) sans réponse signalée(s).");

        return self::SUCCESS;
    }
}
