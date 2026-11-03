<?php

namespace App\Console\Commands;

use App\Domain\Dues\Actions\DuesReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Relances de cotisation (02-TRD §11, RG-COT-05, 06) : J+30, J+60, J+90 au gérant, alerte à la
 * Présidente à J+90 ; journalisée comme toute commande.
 */
class SendDuesReminders extends Command
{
    protected $signature = 'reminders:dues';

    protected $description = 'Relances de cotisation CNCGA après la date butoir, alerte à la Présidente';

    public function handle(DuesReminders $reminders): int
    {
        try {
            $result = $reminders->daily(CarbonImmutable::today());
        } catch (Throwable $exception) {
            Log::error('Cotisations : échec des relances.', ['exception' => $exception]);

            throw $exception;
        }
        $this->info("{$result['reminders']} relance(s), {$result['alerts']} alerte(s).");

        return self::SUCCESS;
    }
}
