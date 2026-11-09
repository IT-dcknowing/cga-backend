<?php

use App\Support\Health\Checks\QueueCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthStatus;
use Illuminate\Support\Facades\Queue;

it('constate que la file sur la base de données répond', function () {
    config(['queue.default' => 'database']);

    expect(app(QueueCheck::class)->run()->status())->toBe(HealthStatus::Ok);
});

it('lève une exception quand la file ne répond pas', function () {
    Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('Redis injoignable'));

    app(QueueCheck::class)->run();
})->throws(RuntimeException::class);

it('signale une file dont le plus ancien travail disponible dépasse le seuil paramétré', function () {
    config(['queue.default' => 'database']);
    $this->travelTo(now()->subMinutes(16));
    Queue::push(static fn () => null);
    $this->travelTo(now()->addMinutes(16));

    $result = app(QueueCheck::class)->run();

    expect($result->error)->toBe(HealthCheckError::Delayed)
        ->and($result->context)->toMatchArray(['queue' => 'default', 'delay_minutes' => 16, 'threshold_minutes' => 15]);
});

it('n’envoie pas d’alerte avant le seuil et ignore les tâches différées', function () {
    config(['queue.default' => 'database']);
    $this->travelTo(now()->subMinutes(14));
    Queue::push(static fn () => null);
    $this->travelTo(now()->addMinutes(14));

    expect(app(QueueCheck::class)->run()->status())->toBe(HealthStatus::Ok);
});
