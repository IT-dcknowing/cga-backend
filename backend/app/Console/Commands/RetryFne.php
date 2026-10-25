<?php

namespace App\Console\Commands;

use App\Domain\Payments\Actions\RetryFneCertifications;
use Illuminate\Console\Command;

/**
 * Nouveaux essais de certification FNE des factures restées en attente parce que le service était
 * indisponible (RG-FNE-04, 02-TRD §11, tâche idempotente toutes les 15 minutes).
 */
class RetryFne extends Command
{
    protected $signature = 'fne:retry';

    protected $description = 'Soumet de nouveau au service FNE les factures dont le nouvel essai est venu';

    public function handle(RetryFneCertifications $retry): int
    {
        $this->info($retry->execute().' facture(s) soumise(s) de nouveau.');

        return self::SUCCESS;
    }
}
