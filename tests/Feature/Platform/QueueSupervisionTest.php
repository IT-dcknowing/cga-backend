<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notifications\EventNotification;
use App\Domain\Platform\Actions\CheckSecurityAlerts;
use App\Domain\Platform\Actions\RetryFailedJob;
use App\Domain\Platform\Models\FailedJob;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Health\Checks\QueueCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Logging\AlertCounter;
use App\Support\Queue\QueueMonitor;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Étape 9.2 (02-TRD §12.3, §6.7 A09) : toutes les files surveillées, écran « Technique » de /plateforme (files,
 * travaux en échec, relance) et alertes de sécurité sur les pics du jour.
 */

function failedJobRow(string $queue = 'documents', string $job = 'App\\Domain\\Documents\\Jobs\\GenerateDocument', ?CarbonImmutable $at = null): string
{
    $uuid = (string) Str::uuid();
    DB::table((string) config('queue.failed.table'))->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => $queue,
        // Charge réelle d'un travail mis en file (relançable par queue:retry), avec une donnée qui ne doit jamais
        // être renvoyée au navigateur.
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => $job,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'tags' => ['secret-data'],
            'attempts' => 3,
            'data' => ['commandName' => CallQueuedClosure::class, 'command' => serialize(CallQueuedClosure::create(static fn () => null))],
        ]),
        'exception' => "RuntimeException: LibreOffice indisponible\n#0 /var/www/app/Support/Files/PdfConverter.php(42)",
        'failed_at' => ($at ?? CarbonImmutable::now())->toDateTimeString(),
    ]);

    return $uuid;
}

describe('files surveillées', function () {
    it('RG-SEC-09 surveille la file par défaut et les files des documents, notifications, intégrations et imports', function () {
        config(['queue.default' => 'database']);

        expect(app(QueueMonitor::class)->queues())->toBe(['default', 'documents', 'notifications', 'integrations', 'imports']);
    });

    it('RG-SEC-09 signale un retard sur une file autre que celle par défaut, la plus en retard d’abord', function () {
        config(['queue.default' => 'database']);
        $this->travelTo(now()->subMinutes(40));
        Queue::pushOn('documents', static fn () => null);
        $this->travelTo(now()->addMinutes(20));
        Queue::pushOn('notifications', static fn () => null);
        $this->travelTo(now()->addMinutes(20));

        $result = app(QueueCheck::class)->run();

        expect($result->error)->toBe(HealthCheckError::Delayed)
            ->and($result->context)->toMatchArray([
                'queue' => 'documents',
                'delay_minutes' => 40,
                'threshold_minutes' => 15,
                'other_delayed_queues' => ['notifications' => 20],
            ]);
    });
});

describe('écran « Technique » de /plateforme', function () {
    beforeEach(function () {
        config(['queue.default' => 'database']);
        $this->admin = Membership::factory()->role(Role::PlatformAdmin)->create();
    });

    it('RG-SEC-09 donne l’état de chaque file avec ses travaux en échec', function () {
        Queue::pushOn('documents', static fn () => null);
        failedJobRow('documents');
        failedJobRow('documents');
        failedJobRow('imports');

        inSpace($this->admin)->getJson('/api/v1/platform/queues')
            ->assertOk()
            ->assertJsonPath('meta.threshold_minutes', 15)
            ->assertJsonPath('data.1', ['queue' => 'documents', 'pending' => 1, 'delay_minutes' => 0, 'delayed' => false, 'failed' => 2])
            ->assertJsonPath('data.4', ['queue' => 'imports', 'pending' => 0, 'delay_minutes' => null, 'delayed' => false, 'failed' => 1]);
    });

    it('RG-SEC-09 liste les travaux en échec sans leur contenu, filtrés par file', function () {
        failedJobRow('documents', at: CarbonImmutable::now()->subHour());
        $recent = failedJobRow('imports', 'App\\Domain\\Exports\\Jobs\\GenerateExport');

        $response = inSpace($this->admin)->getJson('/api/v1/platform/failed-jobs')->assertOk();
        expect($response->json('data.0'))->toMatchArray([
            'uuid' => $recent,
            'queue' => 'imports',
            'job' => 'App\\Domain\\Exports\\Jobs\\GenerateExport',
            'error' => 'RuntimeException: LibreOffice indisponible',
        ])
            ->and($response->getContent())->not->toContain('secret-data')->not->toContain('PdfConverter.php');

        inSpace($this->admin)->getJson('/api/v1/platform/failed-jobs?filter[queue]=documents')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.queue', 'documents');
        inSpace($this->admin)->getJson('/api/v1/platform/failed-jobs?filter[queue]=inconnue')->assertUnprocessable();
        inSpace($this->admin)->getJson('/api/v1/platform/failed-jobs?sort=payload')->assertUnprocessable();
    });

    it('RG-SEC-09 relance un travail en échec et trace la relance', function () {
        $uuid = failedJobRow('documents');

        inSpace($this->admin)->postJson("/api/v1/platform/failed-jobs/{$uuid}/retry")->assertNoContent();

        expect(DB::table((string) config('queue.failed.table'))->where('uuid', $uuid)->exists())->toBeFalse()
            ->and(DB::table('jobs')->where('queue', 'documents')->count())->toBe(1)
            ->and(AuditLog::query()->where('event', 'failed_job_retried')->first()?->context)->toMatchArray(['uuid' => $uuid, 'queue' => 'documents']);

        inSpace($this->admin)->postJson('/api/v1/platform/failed-jobs/'.Str::uuid().'/retry')->assertNotFound();
    });

    it('RG-SEC-09 réserve les files et les travaux en échec à l’administrateur de la plateforme', function (Role $role) {
        $uuid = failedJobRow();
        $agent = Membership::factory()->role($role)->create();

        inSpace($agent)->getJson('/api/v1/platform/queues')->assertForbidden();
        inSpace($agent)->getJson('/api/v1/platform/failed-jobs')->assertForbidden();
        inSpace($agent)->postJson("/api/v1/platform/failed-jobs/{$uuid}/retry")->assertForbidden();
        expect(DB::table((string) config('queue.failed.table'))->where('uuid', $uuid)->exists())->toBeTrue();
    })->with([Role::CncgaPresident, Role::CgaManager, Role::MemberOwner]);
});

describe('alertes de sécurité', function () {
    beforeEach(function () {
        Notification::fake();
        $this->admin = Membership::factory()->role(Role::PlatformAdmin)->create();
        $this->counter = app(AlertCounter::class);
    });

    it('RG-SEC-09 signale une fois par jour un compteur qui atteint son seuil', function () {
        config(['cga.settings.alerts.daily_thresholds.default' => ['login_failed' => 3, 'denied' => 5]]);
        foreach (range(1, 3) as $attempt) {
            $this->counter->increment('login_failed');
        }
        $this->counter->increment('denied');
        Log::spy();

        expect(app(CheckSecurityAlerts::class)->execute(CarbonImmutable::now()))->toBe(['login_failed' => 3])
            ->and(app(CheckSecurityAlerts::class)->execute(CarbonImmutable::now()))->toBe([]);

        Notification::assertSentToTimes($this->admin->user, EventNotification::class, 1);
        Log::shouldHaveReceived('warning')->once()->with('Alerte de sécurité : seuil du jour dépassé.', ['alert' => 'login_failed', 'count' => 3, 'threshold' => 3]);
    });

    it('RG-SEC-09 compte les erreurs serveur et les contournements du cloisonnement pendant une requête', function () {
        Route::middleware('api')->get('api/v1/essai-erreur-serveur', static fn () => throw new RuntimeException('panne'));
        Route::middleware(['api', 'auth', 'tenant'])->get('api/v1/essai-contournement', static fn () => Tenancy::bypass(static fn (): array => ['ok' => true], 'essai'));
        $today = CarbonImmutable::now();

        $this->getJson('/api/v1/essai-erreur-serveur')->assertStatus(500);
        inSpace($this->admin)->getJson('/api/v1/essai-contournement')->assertOk();
        // Tâche planifiée système : aucun espace actif.
        app()->forgetScopedInstances();
        Tenancy::bypass(static fn (): bool => true, 'hors requête : tâche planifiée');

        expect($this->counter->count('server_error', $today))->toBe(1)
            ->and($this->counter->count('tenancy_bypass', $today))->toBe(1)
            ->and($this->counter->count('inconnu', $today))->toBe(0);
    });

    it('RG-SEC-09 affiche les alertes signalées par security:alerts', function () {
        config(['cga.settings.alerts.daily_thresholds.default' => ['denied' => 1]]);

        $this->artisan('security:alerts')->expectsOutput('Aucun seuil dépassé.')->assertSuccessful();

        $this->counter->increment('denied');
        $this->artisan('security:alerts')->expectsOutput('Alertes signalées : denied (1).')->assertSuccessful();
    });

    it('RG-SEC-09 journalise puis relance une erreur de security:alerts', function () {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->andThrow(new RuntimeException('cache indisponible'));
        app()->instance(AlertCounter::class, new AlertCounter($cache, app('config')));
        Log::spy();

        expect(fn () => $this->artisan('security:alerts'))->toThrow(RuntimeException::class, 'cache indisponible');
        Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message): bool => $message === 'Alertes de sécurité : échec du contrôle.');
    });

    it('RG-SEC-09 ne fait jamais échouer la requête quand un compteur est indisponible', function () {
        Exceptions::fake();
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('add')->andThrow(new RuntimeException('cache indisponible'));

        (new AlertCounter($cache, app('config')))->increment('denied');

        Exceptions::assertReported(RuntimeException::class);
    });
});

it('RG-SEC-09 signale une relance refusée par la file', function () {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->once()->andReturn(1);
    $job = new FailedJob;
    $job->forceFill(['uuid' => (string) Str::uuid(), 'queue' => 'documents', 'payload' => '{}', 'exception' => '']);

    expect(fn () => (new RetryFailedJob($kernel, app(AuditLogger::class)))->execute($job))
        ->toThrow(RuntimeException::class, 'La relance du travail a échoué.')
        ->and(AuditLog::query()->where('event', 'failed_job_retried')->exists())->toBeFalse();
});
