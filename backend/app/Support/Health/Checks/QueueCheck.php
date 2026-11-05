<?php

namespace App\Support\Health\Checks;

use App\Support\Config\SettingsService;
use App\Support\Health\HealthCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthCheckResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * Les files d'attente (base de données ou Redis, selon QUEUE_CONNECTION) répondent et ne dépassent pas
 * le délai réglable queue.alert_delay_minutes. Un retard met /api/health en 503 ; le moniteur de santé
 * déclenche alors l'alerte et inscrit les minutes de retard au journal structuré, sans données de job.
 */
class QueueCheck implements HealthCheck
{
    public function __construct(private readonly SettingsService $settings) {}

    public function name(): string
    {
        return 'queue';
    }

    public function run(): HealthCheckResult
    {
        $connectionName = (string) config('queue.default');
        $queue = (string) config("queue.connections.{$connectionName}.queue", 'default');
        $connection = Queue::connection($connectionName);
        $size = $connection->size($queue);

        if ($size === 0) {
            return HealthCheckResult::ok();
        }

        $oldest = $connection->creationTimeOfOldestPendingJob($queue);
        if (! is_numeric($oldest)) {
            return HealthCheckResult::ok();
        }

        $delayMinutes = max(0, (int) floor((CarbonImmutable::now()->getTimestamp() - (int) $oldest) / 60));
        $thresholdMinutes = (int) $this->settings->get('queue.alert_delay_minutes');

        if ($delayMinutes < $thresholdMinutes) {
            return HealthCheckResult::ok();
        }

        return HealthCheckResult::failed(HealthCheckError::Delayed, [
            'queue' => $queue,
            'delay_minutes' => $delayMinutes,
            'threshold_minutes' => $thresholdMinutes,
        ]);
    }
}
