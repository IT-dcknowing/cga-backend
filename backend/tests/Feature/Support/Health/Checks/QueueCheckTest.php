<?php

namespace Tests\Feature\Support\Health\Checks;

use App\Support\Health\Checks\QueueCheck;
use App\Support\Health\HealthStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class QueueCheckTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_database_queue_answers(): void
    {
        config(['queue.default' => 'database']);

        $this->assertSame(HealthStatus::Ok, (new QueueCheck)->run()->status());
    }

    public function test_an_unreachable_queue_raises_an_exception(): void
    {
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('Redis injoignable'));

        $this->expectException(RuntimeException::class);

        (new QueueCheck)->run();
    }
}
