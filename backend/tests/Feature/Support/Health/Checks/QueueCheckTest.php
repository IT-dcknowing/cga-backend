<?php

use App\Support\Health\Checks\QueueCheck;
use App\Support\Health\HealthStatus;
use Illuminate\Support\Facades\Queue;

it('constate que la file sur la base de données répond', function () {
    config(['queue.default' => 'database']);

    expect((new QueueCheck)->run()->status())->toBe(HealthStatus::Ok);
});

it('lève une exception quand la file ne répond pas', function () {
    Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('Redis injoignable'));

    (new QueueCheck)->run();
})->throws(RuntimeException::class);
