<?php

namespace Tests\Feature\Support\Health\Checks;

use App\Support\Health\Checks\DatabaseCheck;
use App\Support\Health\HealthStatus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DatabaseCheckTest extends TestCase
{
    public function test_postgresql_answers(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame(HealthStatus::Ok, (new DatabaseCheck)->run()->status());
    }

    public function test_an_unreachable_database_raises_an_exception(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')->once()->andThrow(new RuntimeException('connexion refusée'));
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->expectException(RuntimeException::class);

        (new DatabaseCheck)->run();
    }
}
