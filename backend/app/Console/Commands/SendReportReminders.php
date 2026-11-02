<?php

namespace App\Console\Commands;

use App\Domain\Reports\Actions\SendReportReminders as SendReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Échéances quotidiennes des rapports trimestriels (02-TRD §11, RG-RPT-06, 07) ; journalisée comme toute
 * commande (SystemActivityListener).
 */
class SendReportReminders extends Command
{
    protected $signature = 'reminders:reports';

    protected $description = 'Relances J-15, J-5, J+5 des rapports trimestriels non transmis, retards et hors délai réglementaire';

    public function handle(SendReminders $reminders): int
    {
        try {
            $result = $reminders->execute(CarbonImmutable::today());
        } catch (Throwable $exception) {
            Log::error('Rapports trimestriels : échec des relances.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info("{$result['reminders']} relance(s), {$result['late']} rapport(s) en retard, {$result['legally_late']} hors délai réglementaire.");

        return self::SUCCESS;
    }
}
