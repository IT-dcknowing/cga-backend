<?php

namespace Tests\Feature\Support\Config;

use App\Support\Config\SettingsService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Registre des paramètres (02-TRD §7.5, §7.6) : une clé lue doit être déclarée dans config/cga.php.
 */
class SettingsServiceTest extends TestCase
{
    public function test_returns_the_default_value_declared_in_the_registry(): void
    {
        $this->assertSame(80, app(SettingsService::class)->get('files.disk_alert_percent'));
    }

    public function test_refuses_a_key_missing_from_the_registry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('files.unknown_key');

        app(SettingsService::class)->get('files.unknown_key');
    }

    public function test_refuses_a_group_of_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SettingsService::class)->get('files');
    }
}
