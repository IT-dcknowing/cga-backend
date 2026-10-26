<?php

namespace App\Console\Commands;

use App\Domain\Members\Actions\ExpirePieces;
use App\Domain\Payments\Actions\MarkOverdueFees;
use Illuminate\Console\Command;

/**
 * Mise à jour nocturne des statuts datés (02-TRD §11, tâche idempotente) : pièces justificatives
 * expirées (RG-PJ-01), échéances d'honoraires impayées (RG-PAY-03). Les rapports en retard s'y
 * ajoutent avec leur lot.
 */
class RefreshStatuses extends Command
{
    protected $signature = 'statuses:refresh';

    protected $description = 'Met à jour les statuts datés : pièces justificatives expirées, honoraires impayés';

    public function handle(ExpirePieces $pieces, MarkOverdueFees $fees): int
    {
        $this->info($pieces->execute().' pièce(s) expirée(s).');
        $this->info($fees->execute().' échéance(s) d\'honoraires impayée(s).');

        return self::SUCCESS;
    }
}
