<?php

namespace App\Console\Commands;

use App\Domain\Closing\Actions\OpenClosingCampaigns;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ouverture annuelle de la campagne de clôture (02-TRD §11, F-OBL-08) : exercice précédent par
 * défaut, dans chaque CGA ; rejouable ; journalisée comme toute commande (SystemActivityListener).
 */
class OpenClosingCampaign extends Command
{
    protected $signature = 'closing:open-campaign {--year= : exercice à clôturer (par défaut, l\'année précédente)}';

    protected $description = 'Ouvre la campagne de clôture de l\'exercice dans chaque CGA';

    public function handle(OpenClosingCampaigns $open): int
    {
        $now = CarbonImmutable::now();
        $year = $this->option('year');
        if ($year !== null && (! is_string($year) || preg_match('/^\d{4}$/', $year) !== 1)) {
            $this->error('Exercice invalide : une année sur 4 chiffres est attendue.');

            return self::INVALID;
        }

        try {
            $result = $open->execute($year === null ? $now->year - 1 : (int) $year, $now);
        } catch (Throwable $exception) {
            Log::error('Campagne de clôture : échec de l\'ouverture.', ['exception' => $exception]);

            throw $exception;
        }

        $this->info("{$result['campaigns']} campagne(s) de clôture ouverte(s) ou complétée(s), {$result['items']} dossier(s) ajouté(s).");

        return self::SUCCESS;
    }
}
