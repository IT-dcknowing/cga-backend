<?php

use App\Domain\Audit\Models\AuditLog;
use App\Support\Database\MonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * audit_logs partitionnée par mois dès sa création (05-BACKEND-SCHEMA §16).
 */

function partitionOf(int $id): string
{
    return DB::selectOne('select tableoid::regclass::text as partition from audit_logs where id = ?', [$id])->partition;
}

it('partitionne audit_logs par mois sur created_at', function () {
    $partitioning = DB::selectOne(
        "select partstrat from pg_partitioned_table where partrelid = 'audit_logs'::regclass",
    );

    expect($partitioning->partstrat)->toBe('r');
});

it('prépare la partition par défaut, celle du mois et celles des mois suivants', function () {
    $now = CarbonImmutable::now()->utc()->startOfMonth();

    expect(Schema::hasTable('audit_logs_default'))->toBeTrue();
    foreach (range(0, config('database.partitions.months_ahead')) as $offset) {
        expect(Schema::hasTable('audit_logs_'.$now->addMonths($offset)->format('Y_m')))->toBeTrue();
    }
});

it('range une ligne dans la partition de son mois, et une ligne hors des mois préparés dans la partition par défaut', function () {
    $now = CarbonImmutable::now()->utc();

    $current = new AuditLog;
    $current->event = 'created';
    $current->save();

    $old = new AuditLog;
    $old->event = 'created';
    $old->created_at = $now->subYears(3);
    $old->save();

    expect(partitionOf($current->id))->toBe('audit_logs_'.$now->format('Y_m'))
        ->and(partitionOf($old->id))->toBe('audit_logs_default');
});

it('crée les partitions des mois qui arrivent, sans rien recréer', function () {
    $later = CarbonImmutable::now()->addMonths(6);

    $created = app(MonthlyPartitions::class)->ensure('audit_logs', $later);

    expect($created)->toContain('audit_logs_'.$later->utc()->format('Y_m'))
        ->and(app(MonthlyPartitions::class)->ensure('audit_logs', $later))->toBe([]);
});

it('refuse une table qui n\'est pas déclarée comme partitionnée', function () {
    app(MonthlyPartitions::class)->ensure('users', CarbonImmutable::now());
})->throws(InvalidArgumentException::class);

it('prépare les partitions chaque jour par la commande logs:partitions, planifiée', function () {
    $this->travelTo(now()->addMonths(8));

    $this->artisan('logs:partitions')->assertSuccessful()->expectsOutputToContain('partition(s) créée(s)');
    $this->artisan('logs:partitions')->assertSuccessful()->expectsOutputToContain('Toutes les partitions existent déjà.');

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'logs:partitions'));
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('5 0 * * *');
});

it('journalise puis relance une erreur de la commande', function () {
    Log::spy();
    config(['database.partitions.tables' => ['Table-Invalide']]);

    expect(fn () => $this->artisan('logs:partitions')->run())->toThrow(InvalidArgumentException::class);
    Log::shouldHaveReceived('error')->once();
});
