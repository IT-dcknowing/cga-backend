<?php

namespace App\Support\Health;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Exécute les contrôles déclarés dans config/health.php. Un contrôle qui échoue n'empêche pas les
 * autres de s'exécuter ; chaque anomalie est journalisée avec son détail technique.
 */
class HealthMonitor
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    public function report(): HealthReport
    {
        $results = [];

        foreach ($this->config->get('health.checks', []) as $checkClass) {
            /** @var HealthCheck $check */
            $check = $this->container->make($checkClass);
            $results[$check->name()] = $this->run($check);
        }

        return new HealthReport($results);
    }

    private function run(HealthCheck $check): HealthCheckResult
    {
        try {
            $result = $check->run();
        } catch (Throwable $exception) {
            Log::error("Point de santé : le contrôle « {$check->name()} » a levé une exception.", [
                'check' => $check->name(),
                'exception' => $exception,
            ]);

            return HealthCheckResult::failed(HealthCheckError::Unavailable);
        }

        if ($result->error !== null) {
            Log::warning("Point de santé : le contrôle « {$check->name()} » est en anomalie.", [
                'check' => $check->name(),
                'error' => $result->error->value,
                ...$result->context,
            ]);
        }

        return $result;
    }
}
