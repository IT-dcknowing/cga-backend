<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Sentry\Laravel\Integration;

it('RG-SEC-09 écrit les journaux applicatifs au format JSON structuré', function () {
    $path = storage_path('framework/testing/supervision-json.log');
    File::ensureDirectoryExists(dirname($path));
    File::delete($path);
    config(['logging.channels.single.path' => $path]);
    Log::forgetChannel('single');

    Log::channel('single')->warning('File en retard', ['queue' => 'default', 'delay_minutes' => 18]);

    $record = json_decode(trim(File::get($path)), true, flags: JSON_THROW_ON_ERROR);
    expect($record['message'])->toBe('File en retard')
        ->and($record['context'])->toMatchArray(['queue' => 'default', 'delay_minutes' => 18]);
});

it('RG-SEC-09 utilise JSON pour les journaux simples et quotidiens et garde les données nominatives hors de Sentry', function () {
    expect(config('logging.channels.single.formatter'))->toBe(JsonFormatter::class)
        ->and(config('logging.channels.daily.formatter'))->toBe(JsonFormatter::class)
        ->and(config('sentry.send_default_pii'))->toBeFalse()
        ->and(class_exists(Integration::class))->toBeTrue();
});
