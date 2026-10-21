<?php

namespace App\Console\Commands;

use App\Domain\Members\Actions\ExpirePieces;
use Illuminate\Console\Command;

/**
 * Mise à jour nocturne des statuts datés (02-TRD §11, tâche idempotente) : pièces justificatives
 * expirées (RG-PJ-01). Les honoraires impayés et les rapports en retard s'y ajoutent avec leurs lots.
 */
class RefreshStatuses extends Command
{
    protected $signature = 'statuses:refresh';

    protected $description = 'Met à jour les statuts datés : pièces justificatives expirées';

    public function handle(ExpirePieces $pieces): int
    {
        $this->info($pieces->execute().' pièce(s) expirée(s).');

        return self::SUCCESS;
    }
}
