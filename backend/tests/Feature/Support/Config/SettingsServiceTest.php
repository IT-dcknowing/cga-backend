<?php

use App\Support\Config\SettingsService;

/*
 * Registre des paramètres (02-TRD §7.5, §7.6) : une clé lue doit être déclarée dans config/cga.php.
 */

it('renvoie la valeur par défaut déclarée dans le registre', function () {
    expect(app(SettingsService::class)->get('files.disk_alert_percent'))->toBe(80);
});

it('refuse une clé absente du registre', function () {
    app(SettingsService::class)->get('files.unknown_key');
})->throws(InvalidArgumentException::class, 'files.unknown_key');

it('refuse un groupe de clés', function () {
    app(SettingsService::class)->get('files');
})->throws(InvalidArgumentException::class);
