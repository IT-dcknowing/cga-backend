<?php

namespace App\Support\Tenancy;

use App\Domain\Audit\AuditLogger;
use InvalidArgumentException;

/**
 * Contournement explicite du cloisonnement (02-TRD §6.3), toujours journalisé avec son motif. Seuls
 * cas admis : statistiques agrégées, détection d'un NCC actif ailleurs (booléen seulement), tâches
 * planifiées système, accès support platform_admin.
 */
class Tenancy
{
    private int $bypassDepth = 0;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function bypass(callable $callback, string $reason): mixed
    {
        return app(self::class)->run($callback, $reason);
    }

    public static function isBypassed(): bool
    {
        return app(self::class)->bypassDepth > 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback, string $reason): mixed
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Un contournement du cloisonnement doit donner son motif.');
        }

        $this->audit->record('tenancy_bypass', context: ['reason' => $reason]);

        $this->bypassDepth++;
        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }
}
