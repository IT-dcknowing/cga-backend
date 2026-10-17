<?php

namespace Tests\Feature\Platform;

use App\Support\Health\HealthCheckResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\FakesHealthChecks;
use Tests\TestCase;

/**
 * GET /api/health (06-IMPLEMENTATION-PLAN étape 0.2, 02-TRD §12.3) : base, files, disque et LibreOffice.
 */
class HealthEndpointTest extends TestCase
{
    use FakesHealthChecks;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'cga.settings.files.disk_alert_percent.default' => 100]);
        Storage::fake();
    }

    public function test_answers_200_without_authentication_when_every_check_is_ok(): void
    {
        Process::fake(['*' => Process::result('LibreOffice 24.8.4.2')]);

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
    }

    public function test_answers_503_and_names_the_failing_check(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'introuvable', exitCode: 1)]);

        $this->getJson('/api/health')
            ->assertServiceUnavailable()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.checks.database', ['status' => 'ok'])
            ->assertJsonPath('data.checks.queue', ['status' => 'ok'])
            ->assertJsonPath('data.checks.storage', ['status' => 'ok'])
            ->assertJsonPath('data.checks.libreoffice', ['status' => 'failed', 'error' => 'unavailable']);
    }

    public function test_reports_low_disk_space(): void
    {
        Process::fake(['*' => Process::result('LibreOffice 24.8.4.2')]);
        config(['cga.settings.files.disk_alert_percent.default' => 0]);

        $this->getJson('/api/health')
            ->assertServiceUnavailable()
            ->assertJsonPath('data.checks.storage', ['status' => 'failed', 'error' => 'low_space']);
    }

    public function test_never_reveals_the_technical_detail_of_a_failure(): void
    {
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
    }
}
