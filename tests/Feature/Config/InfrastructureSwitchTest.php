<?php

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/*
 * Files, cache et sessions sur la base au démarrage, basculables vers Redis par la seule
 * configuration (02-TRD §2, T-12). Ces tests vérifient le choix des pilotes sans serveur Redis ;
 * la suite complète est rejouée sur un vrai Redis par la CI (02-TRD §13, étape 0.5).
 */

it('démarre avec les files, le cache et les sessions sur la base de données', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain("QUEUE_CONNECTION=database\n")
        ->toContain("CACHE_STORE=database\n")
        ->toContain("SESSION_DRIVER=database\n");
});

it('choisit le pilote des files selon QUEUE_CONNECTION', function () {
    config(['queue.default' => 'database']);
    expect(Queue::connection())->toBeInstanceOf(DatabaseQueue::class);

    config(['queue.default' => 'redis']);
    expect(Queue::connection())->toBeInstanceOf(RedisQueue::class);
});

it('choisit le pilote du cache selon CACHE_STORE', function () {
    expect(Cache::store('database')->getStore())->toBeInstanceOf(DatabaseStore::class)
        ->and(Cache::store('redis')->getStore())->toBeInstanceOf(RedisStore::class);
});

it('choisit le pilote des sessions selon SESSION_DRIVER', function () {
    expect(app('session')->driver('database')->getHandler())->toBeInstanceOf(DatabaseSessionHandler::class)
        ->and(app('session')->driver('redis')->getHandler())->toBeInstanceOf(CacheBasedSessionHandler::class);
});

it('lit la connexion Redis dans l\'environnement, sans valeur écrite dans le code', function () {
    expect(config('database.redis.default'))->toHaveKeys(['host', 'port', 'password'])
        ->and(config('queue.connections.redis.connection'))->toBe('default');
});
