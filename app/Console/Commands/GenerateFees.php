<?php

namespace App\Console\Commands;

use App\Domain\Payments\Actions\GenerateFeeCharges;
use Illuminate\Console\Command;

/**
 * Échéances d'honoraires de la période en cours (F-PAY-04, 02-TRD §11, tâche idempotente) : une somme
 * due par dossier validé aux honoraires non nuls et par période de sa périodicité.
 */
class GenerateFees extends Command
{
    protected $signature = 'fees:generate';

    protected $description = 'Crée les échéances d\'honoraires de la période en cours';

    public function handle(GenerateFeeCharges $fees): int
    {
        $this->info($fees->execute().' échéance(s) d\'honoraires créée(s).');

        return self::SUCCESS;
    }
}
