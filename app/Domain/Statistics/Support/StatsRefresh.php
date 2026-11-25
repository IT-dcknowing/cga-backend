<?php

namespace App\Domain\Statistics\Support;

use App\Domain\Members\Events\RadiationCancelled;
use App\Domain\Members\Events\RadiationValidated;
use App\Domain\Payments\Events\PaymentConfirmed;
use App\Domain\Statistics\Jobs\RefreshOrganizationStatsJob;
use Illuminate\Support\Facades\DB;

/**
 * Programme le recalcul ciblé des agrégats d'un CGA (02-TRD §9), avec le délai cga.statistics.refresh_delay_seconds
 * pour regrouper les événements rapprochés : appelé par les Actions qui modifient les données des statistiques
 * (adhésion validée, bascule au portefeuille CGA, paiement d'impôt confirmé) et à l'écoute des événements de
 * paiement confirmé et de radiation.
 *
 * Le Job n'est programmé qu'après la validation de la transaction en cours : le verrou de dédoublonnage est pris
 * hors de toute transaction métier (sous PostgreSQL, un verrou déjà pris en base ferait échouer la transaction
 * entière) et un recalcul n'est jamais programmé pour une écriture annulée.
 */
final class StatsRefresh
{
    public function queue(string $organizationId): void
    {
        DB::afterCommit(static function () use ($organizationId): void {
            RefreshOrganizationStatsJob::dispatch($organizationId)
                ->delay(now()->addSeconds((int) config('cga.statistics.refresh_delay_seconds')));
        });
    }

    /**
     * Écouteur des événements qui modifient les agrégats.
     */
    public function handle(PaymentConfirmed|RadiationValidated|RadiationCancelled $event): void
    {
        $this->queue($event instanceof PaymentConfirmed ? $event->payment->organization_id : $event->radiation->organization_id);
    }
}
