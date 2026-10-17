<?php

namespace App\Support\Health;

/**
 * Un contrôle du point de santé. Une anomalie attendue est renvoyée par HealthCheckResult::failed() ;
 * une exception est traitée par HealthMonitor comme un service indisponible.
 */
interface HealthCheck
{
    /**
     * Nom du contrôle dans la réponse (snake_case).
     */
    public function name(): string;

    public function run(): HealthCheckResult;
}
