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
                ['Adhérents anonymisés (> 10 ans)', $stats['anonymized_members']],
                ['Documents purgés (> 10 ans)', $stats['purged_documents']],
                ['Préoccupations supprimées (> 5 ans)', $stats['purged_concerns']],
                ['Messages supprimés (> 2 ans)', $stats['purged_messages']],
                ['Logs d’activité purgés', $stats['purged_activity_logs']],
                ['Logs d’audit purgés', $stats['purged_audit_logs']],
                ['Dossiers CGA résiliés purgés (> 6 mois)', $stats['purged_terminated_cga_records']],
            ]
        );

        $this->info('Purge de rétention terminée avec succès.');

        return self::SUCCESS;
    }
}

