<?php

namespace Tests\Feature\Support\Health;

use App\Support\Health\Checks\DatabaseCheck;
use App\Support\Health\Checks\LibreOfficeCheck;
use App\Support\Health\Checks\QueueCheck;
use App\Support\Health\Checks\StorageCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthCheckResult;
use App\Support\Health\HealthMonitor;
use App\Support\Health\HealthStatus;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\FakesHealthChecks;
use Tests\TestCase;

class HealthMonitorTest extends TestCase
{
    use FakesHealthChecks;

    public function test_runs_the_database_queue_storage_and_libreoffice_checks(): void
    {
        $this->assertSame(
            [DatabaseCheck::class, QueueCheck::class, StorageCheck::class, LibreOfficeCheck::class],
            config('health.checks'),
        );
    }

    public function test_reports_each_check_under_its_name_in_the_configured_order(): void
    {
        $this->useHealthChecks(
            $this->fakeHealthCheck('database', fn (): HealthCheckResult => HealthCheckResult::ok()),
            $this->fakeHealthCheck('storage', fn (): HealthCheckResult => HealthCheckResult::ok()),
        );

        $report = app(HealthMonitor::class)->report();

        $this->assertSame(['database', 'storage'], array_keys($report->results));
        $this->assertSame(HealthStatus::Ok, $report->status());
    }

    public function test_a_check_that_throws_is_unavailable_and_its_exception_is_logged(): void
    {
        Log::spy();
        $exception = new RuntimeException('connexion refusée');
        $this->useHealthChecks($this->fakeHealthCheck('database', fn () => throw $exception));

        $report = app(HealthMonitor::class)->report();

        $this->assertSame(HealthCheckError::Unavailable, $report->results['database']->error);
        $this->assertSame(HealthStatus::Failed, $report->status());
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'database')
                && $context['check'] === 'database'
                && $context['exception'] === $exception,
        );
    }

    public function test_a_failed_check_is_logged_with_its_technical_context(): void
    {
        Log::spy();
        $this->useHealthChecks($this->fakeHealthCheck(
            'storage',
            fn (): HealthCheckResult => HealthCheckResult::failed(HealthCheckError::LowSpace, ['used_percent' => 97.5]),
        ));

        app(HealthMonitor::class)->report();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context === ['check' => 'storage', 'error' => 'low_space', 'used_percent' => 97.5],
        );
    }

    public function test_a_failing_check_does_not_stop_the_following_ones(): void
    {
        Log::spy();
        $this->useHealthChecks(
            $this->fakeHealthCheck('database', fn () => throw new RuntimeException('panne')),
            $this->fakeHealthCheck('queue', fn (): HealthCheckResult => HealthCheckResult::ok()),
        );

        $report = app(HealthMonitor::class)->report();

        $this->assertSame(HealthStatus::Failed, $report->results['database']->status());
        $this->assertSame(HealthStatus::Ok, $report->results['queue']->status());
    }

    public function test_a_healthy_check_writes_nothing_to_the_log(): void
    {
        Log::spy();
        $this->useHealthChecks($this->fakeHealthCheck('queue', fn (): HealthCheckResult => HealthCheckResult::ok()));

        app(HealthMonitor::class)->report();

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }
}
