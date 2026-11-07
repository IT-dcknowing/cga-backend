<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\Models\FailedJob;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

/**
 * Relance d'un travail en échec depuis l'écran « Technique » de /plateforme (02-TRD §12.3) : queue:retry remet le
 * travail dans sa file avec un compteur d'essais remis à zéro et efface la ligne d'échec ; l'événement est tracé au
 * journal des modifications (sans le contenu du travail).
 */
final class RetryFailedJob
{
    public function __construct(
        private readonly Kernel $artisan,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws RuntimeException relance refusée par la file
     */
    public function execute(FailedJob $job): void
    {
        $uuid = $job->uuid;
        $queue = $job->queue;
        $name = $job->jobName();

        if ($this->artisan->call('queue:retry', ['id' => [$uuid]]) !== 0) {
            throw new RuntimeException('La relance du travail a échoué.');
        }

        $this->audit->record('failed_job_retried', context: ['uuid' => $uuid, 'queue' => $queue, 'job' => $name]);
    }
}
