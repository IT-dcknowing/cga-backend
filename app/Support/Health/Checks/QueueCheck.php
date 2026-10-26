<?php

namespace App\Support\Health\Checks;

use App\Support\Health\HealthCheck;
use App\Support\Health\HealthCheckResult;
use Illuminate\Support\Facades\Queue;

/**
 * Les files d'attente (base de données ou Redis, selon QUEUE_CONNECTION) répondent. Le retard des
 * files (queue.alert_delay_minutes) sera surveillé avec l'étape 9.2.
 */
class QueueCheck implements HealthCheck
{
    public function name(): string
    {
        return 'queue';
    }

    public function run(): HealthCheckResult
    {
        Queue::connection()->size();

        return HealthCheckResult::ok();
    }
}
