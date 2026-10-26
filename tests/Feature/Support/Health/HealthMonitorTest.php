<?php

use App\Support\Health\Checks\DatabaseCheck;
use App\Support\Health\Checks\LibreOfficeCheck;
use App\Support\Health\Checks\QueueCheck;
use App\Support\Health\Checks\StorageCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthCheckResult;
use App\Support\Health\HealthMonitor;
use App\Support\Health\HealthStatus;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesHealthChecks;

uses(FakesHealthChecks::class);

it('exécute les contrôles de la base, des files, du disque et de LibreOffice', function () {
    expect(config('health.checks'))
        ->toBe([DatabaseCheck::class, QueueCheck::class, StorageCheck::class, LibreOfficeCheck::class]);
});

it('range chaque contrôle sous son nom, dans l\'ordre de la configuration', function () {
    $this->useHealthChecks(
        $this->fakeHealthCheck('database', fn (): HealthCheckResult => HealthCheckResult::ok()),
        $this->fakeHealthCheck('storage', fn (): HealthCheckResult => HealthCheckResult::ok()),
    );

    $report = app(HealthMonitor::class)->report();

    expect(array_keys($report->results))->toBe(['database', 'storage'])
        ->and($report->status())->toBe(HealthStatus::Ok);
});

it('déclare indisponible un contrôle qui lève une exception, et journalise l\'exception', function () {
    Log::spy();
    $exception = new RuntimeException('connexion refusée');
    $this->useHealthChecks($this->fakeHealthCheck('database', fn () => throw $exception));

    $report = app(HealthMonitor::class)->report();

    expect($report->results['database']->error)->toBe(HealthCheckError::Unavailable)
        ->and($report->status())->toBe(HealthStatus::Failed);
    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'database')
            && $context['check'] === 'database'
            && $context['exception'] === $exception,
    );
});

it('journalise un contrôle en défaut avec son contexte technique', function () {
    Log::spy();
    $this->useHealthChecks($this->fakeHealthCheck(
        'storage',
        fn (): HealthCheckResult => HealthCheckResult::failed(HealthCheckError::LowSpace, ['used_percent' => 97.5]),
    ));

    app(HealthMonitor::class)->report();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context === ['check' => 'storage', 'error' => 'low_space', 'used_percent' => 97.5],
    );
});

it('exécute les contrôles suivants même si l\'un échoue', function () {
    Log::spy();
    $this->useHealthChecks(
        $this->fakeHealthCheck('database', fn () => throw new RuntimeException('panne')),
        $this->fakeHealthCheck('queue', fn (): HealthCheckResult => HealthCheckResult::ok()),
    );

    $report = app(HealthMonitor::class)->report();

    expect($report->results['database']->status())->toBe(HealthStatus::Failed)
        ->and($report->results['queue']->status())->toBe(HealthStatus::Ok);
});

it('n\'écrit rien dans le journal quand un contrôle est bon', function () {
    Log::spy();
    $this->useHealthChecks($this->fakeHealthCheck('queue', fn (): HealthCheckResult => HealthCheckResult::ok()));

    app(HealthMonitor::class)->report();

    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});
