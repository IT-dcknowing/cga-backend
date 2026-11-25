<?php

use App\Support\Health\Checks\DatabaseCheck;
use App\Support\Health\HealthStatus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

it('constate que PostgreSQL répond', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and((new DatabaseCheck)->run()->status())->toBe(HealthStatus::Ok);
});

it('lève une exception quand la base ne répond pas', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('select')->once()->andThrow(new RuntimeException('connexion refusée'));
    DB::shouldReceive('connection')->once()->andReturn($connection);

    (new DatabaseCheck)->run();
})->throws(RuntimeException::class);
