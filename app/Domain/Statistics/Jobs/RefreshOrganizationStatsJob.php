<?php

namespace App\Domain\Statistics\Jobs;

use App\Domain\Statistics\Actions\RefreshNetworkStats;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recalcul ciblé des agrégats de l'année d'un CGA après un événement qui les modifie (02-TRD §9 : paiement
 * confirmé, adhésion validée, radiation), dédoublonné : un seul recalcul attendu par CGA pendant
 * queue-jobs.refresh_stats.unique_seconds (5 minutes) ; un événement survenu pendant le calcul en programme un
 * nouveau. Sans effet pour un CGA non abonné. Journalisé comme tout Job ; une erreur est journalisée puis
 * relancée.
 */
class RefreshOrganizationStatsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(public readonly string $organizationId)
    {
        $this->onQueue((string) config('queue-jobs.refresh_stats.queue'));
        $this->tries = (int) config('queue-jobs.refresh_stats.tries');
        $this->timeout = (int) config('queue-jobs.refresh_stats.timeout');
        $this->uniqueFor = (int) config('queue-jobs.refresh_stats.unique_seconds');
    }

    public function uniqueId(): string
    {
        return $this->organizationId;
    }

    /**
     * Magasin du verrou de dédoublonnage (queue-jobs.refresh_stats.lock_store ; par défaut, celui du cache).
     */
    public function uniqueVia(): Repository
    {
        $store = config('queue-jobs.refresh_stats.lock_store');

        return Cache::store($store === null ? null : (string) $store);
    }

    public function handle(RefreshNetworkStats $refresh): void
    {
        $now = CarbonImmutable::now();
        try {
            $refresh->execute($now->year, $now, $this->organizationId);
        } catch (Throwable $exception) {
            Log::error('Statistiques : échec du recalcul ciblé des agrégats.', ['organization_id' => $this->organizationId, 'exception' => $exception]);

            throw $exception;
        }
    }
}
