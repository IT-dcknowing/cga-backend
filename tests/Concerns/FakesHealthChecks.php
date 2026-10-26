<?php

namespace Tests\Concerns;

use App\Support\Health\HealthCheck;
use App\Support\Health\HealthCheckResult;
use Closure;

/**
 * Remplace les contrôles de config/health.php par des contrôles de test.
 */
trait FakesHealthChecks
{
    protected function useHealthChecks(HealthCheck ...$checks): void
    {
        $ids = [];

        foreach ($checks as $index => $check) {
            $id = "tests.health.check.{$index}";
            $this->app->instance($id, $check);
            $ids[] = $id;
        }

        config(['health.checks' => $ids]);
    }

    /**
     * @param  Closure(): HealthCheckResult  $run
     */
    protected function fakeHealthCheck(string $name, Closure $run): HealthCheck
    {
        return new class($name, $run) implements HealthCheck
        {
            /**
             * @param  Closure(): HealthCheckResult  $run
             */
            public function __construct(private readonly string $name, private readonly Closure $run) {}

            public function name(): string
            {
                return $this->name;
            }

            public function run(): HealthCheckResult
            {
                return ($this->run)();
            }
        };
    }
}
