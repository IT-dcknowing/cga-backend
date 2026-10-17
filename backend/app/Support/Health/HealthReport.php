<?php

namespace App\Support\Health;

readonly class HealthReport
{
    /**
     * @param  array<string, HealthCheckResult>  $results  résultats par nom de contrôle
     */
    public function __construct(public array $results) {}

    /**
     * Bon seulement si tous les contrôles le sont.
     */
    public function status(): HealthStatus
    {
        foreach ($this->results as $result) {
            if ($result->status() === HealthStatus::Failed) {
                return HealthStatus::Failed;
            }
        }

        return HealthStatus::Ok;
    }
}
