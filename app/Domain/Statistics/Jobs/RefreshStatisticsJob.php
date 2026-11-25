<?php

namespace App\Domain\Statistics\Jobs;

use App\Domain\Statistics\Actions\RefreshNetworkStats;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recalcul demandé par le bouton « Actualiser » (02-TRD §9) : l'année en cours d'un CGA, ou de tout le réseau et
 * de la vue mensuelle pour le CNCGA (organizationId null). Lancé tout de suite, sans dédoublonnage : la demande est
 * limitée par rate_limit.stats_refresh. Journalisé comme tout Job ; une erreur est journalisée puis relancée.
 */
class RefreshStatisticsJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly ?string $organizationId)
    {
        $this->onQueue((string) config('queue-jobs.refresh_stats.queue'));
        $this->tries = (int) config('queue-jobs.refresh_stats.tries');
        $this->timeout = (int) config('queue-jobs.refresh_stats.timeout');
    }

    public function handle(RefreshNetworkStats $refresh): void
    {
        $now = CarbonImmutable::now();
        try {
            $refresh->execute($now->year, $now, $this->organizationId);
        } catch (Throwable $exception) {
            Log::error('Statistiques : échec du recalcul demandé.', ['organization_id' => $this->organizationId, 'exception' => $exception]);

            throw $exception;
        }
    }
}
