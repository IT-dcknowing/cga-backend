<?php

use App\Support\Database\MonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

it('prévisualise les partitions échues puis ne les supprime qu’avec --execute', function () {
    $activityStart = CarbonImmutable::now('UTC')->subMonths(30)->startOfMonth();
    $auditStart = CarbonImmutable::now('UTC')->subMonths(70)->startOfMonth();

    $activityPartitions = app(MonthlyPartitions::class)->ensure('activity_logs', $activityStart);
    $auditPartitions = app(MonthlyPartitions::class)->ensure('audit_logs', $auditStart);

    expect($activityPartitions)->not->toBeEmpty()
        ->and($auditPartitions)->not->toBeEmpty();

    $this->artisan('logs:prune')
        ->assertSuccessful()
        ->expectsOutputToContain('Simulation uniquement');

    foreach ([...$activityPartitions, ...$auditPartitions] as $partition) {
        expect(Schema::hasTable($partition))->toBeTrue();
    }

    $this->artisan('logs:prune', ['--execute' => true])
        ->assertFailed()
        ->expectsOutputToContain('Purge bloquée');

    foreach ([...$activityPartitions, ...$auditPartitions] as $partition) {
        expect(Schema::hasTable($partition))->toBeTrue();
    }

    $this->artisan('logs:prune', [
        '--execute' => true,
        '--confirm-no-legal-hold' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Supprimée');

    foreach ([...$activityPartitions, ...$auditPartitions] as $partition) {
        expect(Schema::hasTable($partition))->toBeFalse();
    }
});
