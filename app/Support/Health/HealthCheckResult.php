<?php

namespace App\Support\Health;

/**
 * Résultat d'un contrôle. Le contexte sert au journal et n'est jamais renvoyé au client.
 */
readonly class HealthCheckResult
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public ?HealthCheckError $error,
        public array $context,
    ) {}

    public static function ok(): self
    {
        return new self(null, []);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function failed(HealthCheckError $error, array $context = []): self
    {
        return new self($error, $context);
    }

    public function status(): HealthStatus
    {
        return $this->error === null ? HealthStatus::Ok : HealthStatus::Failed;
    }
}
