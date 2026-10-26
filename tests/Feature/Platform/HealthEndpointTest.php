<?php

use App\Support\Health\HealthCheckResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesHealthChecks;

/*
 * GET /api/health (06-IMPLEMENTATION-PLAN étape 0.2, 02-TRD §12.3) : base, files, disque et LibreOffice.
 */

uses(FakesHealthChecks::class);

beforeEach(function () {
    config(['queue.default' => 'database', 'cga.settings.files.disk_alert_percent.default' => 100]);
    Storage::fake();
});

it('répond 200 sans authentification quand tous les contrôles sont bons', function () {
    Process::fake(['*' => Process::result('LibreOffice 26.2.6.3')]);

    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson(['data' => [
            'status' => 'ok',
            'checks' => [
                'database' => ['status' => 'ok'],
                'queue' => ['status' => 'ok'],
                'storage' => ['status' => 'ok'],
                'libreoffice' => ['status' => 'ok'],
            ],
        ]]);
});

it('répond 503 et nomme le contrôle en défaut', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'introuvable', exitCode: 1)]);

    $this->getJson('/api/health')
        ->assertServiceUnavailable()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.checks.database', ['status' => 'ok'])
        ->assertJsonPath('data.checks.queue', ['status' => 'ok'])
        ->assertJsonPath('data.checks.storage', ['status' => 'ok'])
        ->assertJsonPath('data.checks.libreoffice', ['status' => 'failed', 'error' => 'unavailable']);
});

it('signale un disque presque plein', function () {
    Process::fake(['*' => Process::result('LibreOffice 26.2.6.3')]);
    config(['cga.settings.files.disk_alert_percent.default' => 0]);

    $this->getJson('/api/health')
        ->assertServiceUnavailable()
        ->assertJsonPath('data.checks.storage', ['status' => 'failed', 'error' => 'low_space']);
});

it('ne révèle jamais le détail technique d\'une panne', function () {
    Log::spy();
    $this->useHealthChecks(
        $this->fakeHealthCheck('database', fn () => throw new RuntimeException('SQLSTATE[08006] host=10.0.0.5 password=secret')),
        $this->fakeHealthCheck('queue', fn (): HealthCheckResult => HealthCheckResult::ok()),
    );

    $this->getJson('/api/health')
        ->assertServiceUnavailable()
        ->assertExactJson(['data' => [
            'status' => 'failed',
            'checks' => [
                'database' => ['status' => 'failed', 'error' => 'unavailable'],
                'queue' => ['status' => 'ok'],
            ],
        ]])
        ->assertDontSee('secret')
        ->assertDontSee('10.0.0.5');
    Log::shouldHaveReceived('error')->once();
});

it('n\'ouvre pas de session, même appelé depuis le SPA', function () {
    Process::fake(['*' => Process::result('LibreOffice 26.2.6.3')]);

    $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'])
        ->getJson('/api/health')
        ->assertOk()
        ->assertCookieMissing(config('session.cookie'))
        ->assertCookieMissing('XSRF-TOKEN');
});
