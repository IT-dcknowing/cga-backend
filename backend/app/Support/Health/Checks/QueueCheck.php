<?php

namespace App\Support\Health\Checks;

use App\Support\Health\HealthCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthCheckResult;
use App\Support\Queue\QueueMonitor;

/**
 * Les files d'attente (base de données ou Redis, selon QUEUE_CONNECTION) répondent et aucune file surveillée
 * (QueueMonitor : défaut, documents, notifications, intégrations, imports) ne dépasse le délai réglable
 * queue.alert_delay_minutes. Un retard met /api/health en 503 ; le moniteur de santé déclenche alors l'alerte et
 * inscrit les minutes de retard au journal structuré (la file la plus en retard, puis les autres files en
 * retard s'il y en a), sans données de job.
 */
class QueueCheck implements HealthCheck
{
    public function __construct(private readonly QueueMonitor $monitor) {}

    public function name(): string
    {
        return 'queue';
    }

    public function run(): HealthCheckResult
    {
        $delayed = [];
        foreach ($this->monitor->snapshot() as $state) {
            if ($state['delayed']) {
                $delayed[$state['queue']] = (int) $state['delay_minutes'];
            }
        }

        if ($delayed === []) {
            return HealthCheckResult::ok();
        }

        arsort($delayed);
        $worst = (string) array_key_first($delayed);
        $others = array_diff_key($delayed, [$worst => true]);

        return HealthCheckResult::failed(HealthCheckError::Delayed, [
            'queue' => $worst,
            'delay_minutes' => $delayed[$worst],
            'threshold_minutes' => $this->monitor->thresholdMinutes(),
            ...($others === [] ? [] : ['other_delayed_queues' => $others]),
        ]);
    }
}
