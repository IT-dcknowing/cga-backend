<?php

namespace App\Console\Commands;

use App\Domain\Platform\Actions\CheckSecurityAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `security:alerts` (02-TRD §6.7, A09, toutes les 15 minutes) : signale aux administrateurs de la plateforme les
 * compteurs de sécurité du jour qui dépassent leur seuil. Journalisée comme toute commande ; une erreur est
 * journalisée puis relancée.
 */
class CheckSecurityAlertsCommand extends Command
{
    protected $signature = 'security:alerts';

    protected $description = 'Signale les pics d’échecs de connexion, de refus, d’erreurs et de contournements';

    public function handle(CheckSecurityAlerts $check): int
    {
        try {
            $raised = $check->execute(CarbonImmutable::now());
        } catch (Throwable $exception) {
            Log::error('Alertes de sécurité : échec du contrôle.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info($raised === []
            ? 'Aucun seuil dépassé.'
            : 'Alertes signalées : '.implode(', ', array_map(static fn (string $alert, int $count): string => "{$alert} ({$count})", array_keys($raised), $raised)).'.');

        return self::SUCCESS;
    }
}
