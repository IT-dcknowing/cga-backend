<?php

use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthCheckResult;
use App\Support\Health\HealthReport;
use App\Support\Health\HealthStatus;

it('est bon quand tous les contrôles sont bons', function () {
    $report = new HealthReport(['database' => HealthCheckResult::ok(), 'queue' => HealthCheckResult::ok()]);

    expect($report->status())->toBe(HealthStatus::Ok);
});

it('est en défaut dès qu\'un contrôle l\'est', function () {
    $report = new HealthReport([
        'database' => HealthCheckResult::ok(),
        'storage' => HealthCheckResult::failed(HealthCheckError::LowSpace),
        'queue' => HealthCheckResult::ok(),
    ]);

    expect($report->status())->toBe(HealthStatus::Failed);
});

it('garde le code et le contexte de journal d\'un contrôle en défaut', function () {
    $result = HealthCheckResult::failed(HealthCheckError::NotWritable, ['disk' => 'private']);

    expect($result->status())->toBe(HealthStatus::Failed)
        ->and($result->error)->toBe(HealthCheckError::NotWritable)
        ->and($result->context)->toBe(['disk' => 'private']);
});

it('n\'a ni code ni contexte pour un contrôle bon', function () {
    $result = HealthCheckResult::ok();

    expect($result->status())->toBe(HealthStatus::Ok)
        ->and($result->error)->toBeNull()
        ->and($result->context)->toBe([]);
});
