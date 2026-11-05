<?php

use App\Support\Database\MonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

it('prévisualise les partitions échues puis ne les supprime qu’avec --execute', function () {
    $now = CarbonImmutable::now('UTC')->startOfMonth();
    $activityExpiredStart = $now->subMonths(30);
    $auditExpiredStart = $now->subMonths(70);

    $activityExpiredPartitions = app(MonthlyPartitions::class)->ensure('activity_logs', $activityExpiredStart);
    $auditExpiredPartitions = app(MonthlyPartitions::class)->ensure('audit_logs', $auditExpiredStart);
    $activityCurrentPartitions = app(MonthlyPartitions::class)->ensure('activity_logs', $now);
    $auditCurrentPartitions = app(MonthlyPartitions::class)->ensure('audit_logs', $now);

    expect($activityExpiredPartitions)->not->toBeEmpty()
        ->and($auditExpiredPartitions)->not->toBeEmpty()
        ->and($activityCurrentPartitions)->not->toBeEmpty()
        ->and($auditCurrentPartitions)->not->toBeEmpty();

    $allPartitions = [
        ...$activityExpiredPartitions,
        ...$auditExpiredPartitions,
        ...$activityCurrentPartitions,
        ...$auditCurrentPartitions,
    ];

    $this->artisan('logs:prune')
        ->assertSuccessful()
        ->expectsOutputToContain('Simulation uniquement');

    foreach ($allPartitions as $partition) {
        expect(Schema::hasTable($partition))->toBeTrue();
    }

    $this->artisan('logs:prune', ['--execute' => true])
        ->assertFailed()
        ->expectsOutputToContain('Purge bloquée');

    foreach ($allPartitions as $partition) {
        expect(Schema::hasTable($partition))->toBeTrue();
    }

    $this->artisan('logs:prune', [
        '--execute' => true,
        '--confirm-no-legal-hold' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Supprimée');

    foreach ([...$activityExpiredPartitions, ...$auditExpiredPartitions] as $partition) {
        expect(Schema::hasTable($partition))->toBeFalse();
    }

    foreach ([...$activityCurrentPartitions, ...$auditCurrentPartitions] as $partition) {
        expect(Schema::hasTable($partition))->toBeTrue();
    }
});
