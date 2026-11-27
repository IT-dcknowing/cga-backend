<?php

namespace App\Console\Commands;

use App\Domain\Platform\Actions\PurgeRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Exécute les purges et anonymisations prévues par le PRD §7 et RG-ORG-07 (tâche hebdomadaire).
 */
class PurgeRetentionCommand extends Command
{
    protected $signature = 'retention:purge {--dry-run : Afficher ce qui serait purgé sans modifier la base}';

    protected $description = 'Purge et anonymise les données périmées selon les durées de rétention (PRD §7, RG-ORG-07)';

    public function handle(PurgeRetention $action): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Mode simulation (aucun enregistrement ne sera modifié ni supprimé).');
        }

        $stats = $action->execute(CarbonImmutable::now(), $dryRun);

        $this->table(
            ['Catégorie', 'Éléments traités'],
            [
                ['Adhérents radiés anonymisés', $stats['anonymized_members']],
                ['Contrats, attestations, reçus et factures supprimés', $stats['purged_documents']],
                ['Préoccupations supprimées', $stats['purged_concerns']],
                ['Messages supprimés', $stats['purged_messages']],
                ['Journaux d’activité purgés', $stats['purged_activity_logs']],
                ['Journaux d’audit purgés', $stats['purged_audit_logs']],
                ['CGA résiliés purgés (données sans durée légale)', $stats['purged_terminated_organizations']],
            ]
        );

        $this->info('Purge de rétention terminée avec succès.');

        return self::SUCCESS;
    }
}
