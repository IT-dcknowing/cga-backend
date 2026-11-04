<?php

namespace App\Console\Commands;

use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purge contrôlée des partitions mensuelles des journaux (RG-SEC-05).
 *
 * La simulation est le comportement par défaut. L'option --execute est volontairement requise
 * pour toute suppression. Seules les partitions mensuelles nommées selon la convention du projet
 * sont candidates ; les partitions DEFAULT et les tables non déclarées sont toujours ignorées.
 */
class PruneLogPartitions extends Command
{
    protected $signature = 'logs:prune
        {--execute : Supprime réellement les partitions arrivées au terme de leur rétention}
        {--confirm-no-legal-hold : Confirme que les obligations de gel juridique ont été vérifiées}';

    protected $description = 'Prévisualise ou purge les partitions mensuelles de journaux arrivées à échéance';

    public function handle(SettingsService $settings): int
    {
        if ($this->option('execute') && ! $this->option('confirm-no-legal-hold')) {
            $this->error('Purge bloquée : vérifiez les gels juridiques et ajoutez --confirm-no-legal-hold après validation.');
            return self::FAILURE;
        }

        try {
            $policies = [
                'activity_logs' => (int) $settings->get('logs.activity_retention_months'),
                'audit_logs' => (int) $settings->get('logs.audit_retention_months'),
            ];

            foreach ($policies as $table => $retentionMonths) {
                if ($retentionMonths < 1) {
                    throw new \InvalidArgumentException("La rétention de {$table} doit être supérieure ou égale à un mois.");
                }

                $cutoff = CarbonImmutable::now('UTC')->startOfMonth()->subMonths($retentionMonths);
                $partitions = $this->eligiblePartitions($table, $cutoff);

                $this->line(sprintf(
                    '%s : %d partition(s) antérieure(s) au %s (rétention : %d mois).',
                    $table,
                    count($partitions),
                    $cutoff->toDateString(),
                    $retentionMonths,
                ));

                foreach ($partitions as $partition) {
                    if (! $this->option('execute')) {
                        $this->line("  Simulation : {$partition}");
                        continue;
                    }

                    // Le nom vient du catalogue PostgreSQL et doit aussi respecter la convention stricte.
                    if (! preg_match('/^'.preg_quote($table, '/').'_[0-9]{4}_[0-9]{2}$/D', $partition)) {
                        throw new \UnexpectedValueException("Nom de partition inattendu : {$partition}");
                    }

                    DB::statement('DROP TABLE "'.$partition.'"');
                    Log::notice('Partition de journal supprimée après expiration de rétention.', [
                        'table' => $table,
                        'partition' => $partition,
                        'retention_months' => $retentionMonths,
                        'cutoff' => $cutoff->toDateString(),
                    ]);
                    $this->info("  Supprimée : {$partition}");
                }
            }

            if (! $this->option('execute')) {
                $this->comment('Simulation uniquement. Ajouter --execute après validation de la politique de conservation pour supprimer les partitions.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Échec de la purge des partitions de journaux.', [
                'execute' => (bool) $this->option('execute'),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @return list<string>
     */
    private function eligiblePartitions(string $table, CarbonImmutable $cutoff): array
    {
        if (! in_array($table, ['activity_logs', 'audit_logs'], true)) {
            throw new \InvalidArgumentException("Table de journaux non autorisée : {$table}");
        }

        $rows = DB::select(
            <<<'SQL'
                SELECT child.relname AS partition_name
                FROM pg_inherits
                INNER JOIN pg_class parent ON parent.oid = pg_inherits.inhparent
                INNER JOIN pg_class child ON child.oid = pg_inherits.inhrelid
                WHERE parent.relname = ?
                ORDER BY child.relname
                SQL,
            [$table],
        );

        $eligible = [];
        foreach ($rows as $row) {
            $name = (string) $row->partition_name;
            if (! preg_match('/^'.preg_quote($table, '/').'_(\d{4})_(\d{2})$/D', $name, $matches)) {
                continue;
            }

            $year = (int) $matches[1];
            $month = (int) $matches[2];
            if ($month < 1 || $month > 12) {
                continue;
            }

            $partitionStart = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');
            if ($partitionStart->addMonth()->lessThanOrEqualTo($cutoff)) {
                $eligible[] = $name;
            }
        }

        return $eligible;
    }
}
