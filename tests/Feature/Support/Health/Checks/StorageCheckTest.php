<?php

use App\Support\Config\SettingsService;
use App\Support\Health\Checks\StorageCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthStatus;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

/*
 * Disque de stockage : écriture, relecture et seuil d'occupation files.disk_alert_percent (02-TRD §6.4).
 */

function setDiskAlertPercent(int $percent): void
{
    config(['cga.settings.files.disk_alert_percent.default' => $percent]);
}

function fakeStorageDisk(bool $writes, ?string $readsBack = null): void
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturn($writes);
    $disk->shouldReceive('get')->andReturn($readsBack);
    $disk->shouldReceive('delete')->once()->andReturn(true);
    Storage::shouldReceive('disk')->andReturn($disk);
}

it('est bon pour un disque inscriptible sous le seuil, sans laisser de fichier témoin', function () {
    Storage::fake();
    setDiskAlertPercent(100);

    expect(app(StorageCheck::class)->run()->status())->toBe(HealthStatus::Ok)
        ->and(Storage::disk()->allFiles())->toBe([]);
});

it('signale un disque qui refuse l\'écriture', function () {
    fakeStorageDisk(writes: false);

    $result = app(StorageCheck::class)->run();

    expect($result->error)->toBe(HealthCheckError::NotWritable)
        ->and($result->context)->toBe(['disk' => 'private']);
});

it('signale un disque qui relit un autre contenu', function () {
    fakeStorageDisk(writes: true, readsBack: 'autre contenu');

    expect(app(StorageCheck::class)->run()->error)->toBe(HealthCheckError::NotWritable);
});

it('signale un disque occupé au-delà du seuil', function () {
    Storage::fake();
    setDiskAlertPercent(0);

    $result = app(StorageCheck::class)->run();

    expect($result->error)->toBe(HealthCheckError::LowSpace)
        ->and($result->context['alert_percent'])->toBe(0)
        ->and($result->context['used_percent'])->toBeGreaterThan(0);
});

it('lit son seuil dans le registre des paramètres', function () {
    Storage::fake();

    setDiskAlertPercent(100);
    expect(app(StorageCheck::class)->run()->status())->toBe(HealthStatus::Ok);

    setDiskAlertPercent(0);
    expect(app(StorageCheck::class)->run()->status())->toBe(HealthStatus::Failed);
});

it('ne mesure pas un stockage sans racine locale', function () {
    Storage::fake();
    config(['filesystems.disks.private.root' => null]);
    setDiskAlertPercent(0);

    expect(app(StorageCheck::class)->run()->status())->toBe(HealthStatus::Ok);
});

it('lève une exception quand l\'espace du disque est illisible', function () {
    Storage::fake();
    config(['filesystems.disks.private.root' => storage_path('framework/testing/dossier-inexistant')]);

    app(StorageCheck::class)->run();
})->throws(RuntimeException::class);

it('prend le seuil par défaut du registre quand la base ne répond pas', function () {
    Storage::fake();
    $settings = Mockery::mock(SettingsService::class);
    $settings->shouldReceive('get')->andThrow(new QueryException('pgsql', 'select', [], new RuntimeException('connexion refusée')));
    $settings->shouldReceive('default')->with('files.disk_alert_percent')->andReturn(0);
    app()->instance(SettingsService::class, $settings);

    $result = app(StorageCheck::class)->run();

    expect($result->error)->toBe(HealthCheckError::LowSpace)
        ->and($result->context['alert_percent'])->toBe(0);
});
