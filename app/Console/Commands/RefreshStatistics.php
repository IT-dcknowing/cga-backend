<?php

namespace App\Console\Commands;

use App\Domain\Statistics\Actions\RefreshNetworkStats;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `stats:refresh` (02-TRD §9 et §11, chaque nuit) : agrégats journaliers de l'année en cours de chaque CGA abonné
 * et vue mensuelle du réseau. `--year` recalcule une année passée, `--organization` un seul CGA. Journalisée comme
 * toute commande (SystemActivityListener) ; une erreur est journalisée puis relancée.
 */
class RefreshStatistics extends Command
{
    protected $signature = 'stats:refresh {--year= : année à recalculer (par défaut l\'année en cours)} {--organization= : identifiant d\'un seul CGA}';

    protected $description = 'Recalcul des agrégats statistiques des CGA abonnés et du réseau';

    public function handle(RefreshNetworkStats $refresh): int
    {
        $now = CarbonImmutable::now();
        $year = $this->option('year');
        if ($year !== null && (! ctype_digit((string) $year) || (int) $year > $now->year)) {
            $this->error('Année invalide.');

            return self::INVALID;
        }
        $organization = $this->option('organization');

        try {
            $result = $refresh->execute($year === null ? $now->year : (int) $year, $now, $organization === null ? null : (string) $organization);
        } catch (Throwable $exception) {
            Log::error('Statistiques : échec du recalcul des agrégats.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info("{$result['organizations']} CGA recalculé(s), {$result['days']} jour(s).");

        return self::SUCCESS;
    }
}
