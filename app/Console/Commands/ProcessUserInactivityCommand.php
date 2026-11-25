<?php

namespace App\Console\Commands;

use App\Domain\Users\Actions\ProcessUserInactivity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** `users:inactivity` (RG-ACC-06) : désactivation, avis à 23 mois et anonymisation à 24 mois. */
class ProcessUserInactivityCommand extends Command
{
    protected $signature = 'users:inactivity';

    protected $description = 'Désactive et anonymise les comptes utilisateurs inactifs';

    public function handle(ProcessUserInactivity $process): int
    {
        try {
            $counts = $process->execute(CarbonImmutable::now());
        } catch (Throwable $exception) {
            Log::error('Comptes inactifs : échec du traitement.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info("{$counts['disabled']} désactivé(s), {$counts['notified']} informé(s), {$counts['anonymized']} anonymisé(s).");

        return self::SUCCESS;
    }
}
