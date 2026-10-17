<?php

namespace Tests\Feature\Support\Health\Checks;

use App\Support\Health\Checks\StorageCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthStatus;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Disque de stockage : écriture, relecture et seuil d'occupation files.disk_alert_percent (02-TRD §6.4).
 */
class StorageCheckTest extends TestCase
{
    public function test_a_writable_disk_under_the_threshold_is_ok_and_keeps_no_probe_file(): void
    {
        Storage::fake();
        $this->setDiskAlertPercent(100);

        $result = app(StorageCheck::class)->run();

        $this->assertSame(HealthStatus::Ok, $result->status());
        $this->assertSame([], Storage::disk()->allFiles());
    }

    public function test_a_disk_that_refuses_writing_is_not_writable(): void
    {
        $this->fakeDisk(writes: false);

        $result = app(StorageCheck::class)->run();

        $this->assertSame(HealthCheckError::NotWritable, $result->error);
        $this->assertSame(['disk' => 'local'], $result->context);
    }

    public function test_a_disk_that_returns_other_content_is_not_writable(): void
    {
        $this->fakeDisk(writes: true, readsBack: 'autre contenu');

        $this->assertSame(HealthCheckError::NotWritable, app(StorageCheck::class)->run()->error);
    }

    public function test_a_disk_used_beyond_the_threshold_has_low_space(): void
    {
        Storage::fake();
        $this->setDiskAlertPercent(0);

        $result = app(StorageCheck::class)->run();

        $this->assertSame(HealthCheckError::LowSpace, $result->error);
        $this->assertSame(0, $result->context['alert_percent']);
        $this->assertGreaterThan(0, $result->context['used_percent']);
    }

    public function test_the_threshold_comes_from_the_settings_registry(): void
    {
        Storage::fake();
        $this->setDiskAlertPercent(100);
        $this->assertSame(HealthStatus::Ok, app(StorageCheck::class)->run()->status());

        $this->setDiskAlertPercent(0);
        $this->assertSame(HealthStatus::Failed, app(StorageCheck::class)->run()->status());
    }

    public function test_a_disk_without_local_root_is_not_measured(): void
    {
        Storage::fake();
        config(['filesystems.disks.local.root' => null]);
        $this->setDiskAlertPercent(0);

        $this->assertSame(HealthStatus::Ok, app(StorageCheck::class)->run()->status());
    }

    public function test_an_unreadable_disk_space_raises_an_exception(): void
    {
        Storage::fake();
        config(['filesystems.disks.local.root' => storage_path('framework/testing/dossier-inexistant')]);

        $this->expectException(RuntimeException::class);

        app(StorageCheck::class)->run();
    }

    private function setDiskAlertPercent(int $percent): void
    {
        config(['cga.settings.files.disk_alert_percent.default' => $percent]);
    }

    private function fakeDisk(bool $writes, ?string $readsBack = null): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturn($writes);
        $disk->shouldReceive('get')->andReturn($readsBack);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->andReturn($disk);
    }
}
