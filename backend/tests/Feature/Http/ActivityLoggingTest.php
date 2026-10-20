<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Jobs\SampleJob;

/*
 * Journalisation de toute action, lectures comprises (RG-SEC-05, 02-TRD §6.5) : requêtes, Jobs, tâches
 * planifiées, commandes ; X-Request-Id ; aucun secret ; l'écriture ne fait jamais échouer l'action.
 */

function loggedRequest(TestResponse $response): ActivityLog
{
    return ActivityLog::query()->where('request_id', $response->headers->get('X-Request-Id'))->sole();
}

it('journalise chaque route de l\'application', function () {
    Process::fake(['*' => Process::result('LibreOffice 26.2.6.3')]);
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn (RouteDefinition $route): bool => ! str_starts_with($route->uri(), 'essai'));
    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $uri = preg_replace('/\{[^}]+\}/', strtolower((string) Str::ulid()), $route->uri());
        $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');

        $response = $this->json($method, '/'.ltrim($uri, '/'));

        $log = loggedRequest($response);
        expect($log->method)->toBe($method)
            ->and($log->route)->toBe('/'.ltrim($route->uri(), '/'))
            ->and($log->http_status)->toBe($response->getStatusCode());
    }
});

it('nomme l\'action par la route ou par handle()', function () {
    Process::fake(['*' => Process::result('LibreOffice 26.2.6.3')]);

    expect(loggedRequest($this->getJson('/api/health'))->action)->toBe('platform.health')
        ->and(loggedRequest($this->get('/sanctum/csrf-cookie'))->action)->toBe('sanctum.csrf-cookie')
        ->and(loggedRequest($this->getJson('/api/v1/rien'))->action)->toBe('GET /api/v1/rien');
});

it('pose un X-Request-Id sur chaque réponse, repris du client s\'il est bien formé', function () {
    $generated = $this->getJson('/api/v1/rien');
    $kept = $this->withHeaders(['X-Request-Id' => 'trace-client-1234'])->getJson('/api/v1/rien');
    $replaced = $this->withHeaders(['X-Request-Id' => "invalide\n<script>"])->getJson('/api/v1/rien');

    expect($generated->headers->get('X-Request-Id'))->toMatch('/^[0-9a-z]{26}$/')
        ->and($kept->headers->get('X-Request-Id'))->toBe('trace-client-1234')
        ->and($replaced->headers->get('X-Request-Id'))->toMatch('/^[0-9a-z]{26}$/')
        ->and(loggedRequest($kept)->request_id)->toBe('trace-client-1234');
});

it('ne garde aucun secret en clair dans les paramètres, à toute profondeur, et tronque les longs textes', function () {
    Route::middleware('api')->post('api/v1/essai-parametres', fn () => response()->noContent());

    $response = $this->postJson('/api/v1/essai-parametres?page=2', [
        'phone' => '+2250700000001',
        'password' => 'secret-de-test',
        'profil' => ['Token' => 'jeton-secret', 'nom' => 'Koné'],
        'note' => str_repeat('a', 600),
    ]);

    $params = loggedRequest($response)->params;
    expect($params['password'])->toBe('***')
        ->and($params['profil'])->toEqual(['Token' => '***', 'nom' => 'Koné'])
        ->and($params['phone'])->toBe('+2250700000001')
        ->and($params['page'])->toBe('2')
        ->and(mb_strlen($params['note']))->toBe(501)
        ->and(json_encode($params))->not->toContain('secret');
});

it('décrit un fichier envoyé par son nom et sa taille seulement', function () {
    Route::middleware('api')->post('api/v1/essai-fichier', fn () => response()->noContent());

    $response = $this->post('/api/v1/essai-fichier', ['piece' => UploadedFile::fake()->create('releve.pdf', 12)]);

    expect(loggedRequest($response)->params['piece'])->toBe(['file' => 'releve.pdf', 'size' => 12 * 1024]);
});

it('remplace des paramètres trop volumineux par la liste de leurs clés', function () {
    config(['activity.params_max_bytes' => 50]);
    Route::middleware('api')->post('api/v1/essai-parametres', fn () => response()->noContent());

    $response = $this->postJson('/api/v1/essai-parametres', ['a' => str_repeat('x', 100), 'b' => 'y']);

    // jsonb réordonne les clés : la comparaison porte sur les valeurs.
    expect(loggedRequest($response)->params)->toEqual(['_truncated' => true, 'keys' => ['a', 'b']]);
});

it('retient l\'utilisateur, son espace et l\'objet visé', function () {
    $membership = Membership::factory()->create();
    Route::middleware(['api', 'auth', 'tenant'])->get('api/v1/essai-sujet/{organization}', fn (Organization $organization) => ['id' => $organization->id]);

    $response = $this->actingAs($membership->user)
        ->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'])
        ->withSession([TenantContext::SESSION_KEY => $membership->id])
        ->getJson('/api/v1/essai-sujet/'.$membership->organization_id)
        ->assertOk();

    expect(loggedRequest($response))
        ->actor_type->toBe('user')
        ->user_id->toBe($membership->user_id)
        ->membership_id->toBe($membership->id)
        ->role->toBe('cga_accountant')
        ->organization_id->toBe($membership->organization_id)
        ->subject_type->toBe(Organization::class)
        ->subject_id->toBe($membership->organization_id)
        ->duration_ms->toBeInt();
});

it('n\'échoue jamais à cause du journal : la ligne part dans le journal de secours', function () {
    $fallbackFile = storage_path('framework/testing/activity-fallback-'.Str::random(8).'.log');
    config(['logging.channels.activity_fallback.path' => $fallbackFile]);
    // La table du journal disparaît le temps du test (annulé avec la transaction du test).
    DB::statement('ALTER TABLE activity_logs RENAME TO activity_logs_indisponible');

    $this->getJson('/api/v1/rien')->assertNotFound()->assertJsonPath('code', 'not_found');

    $line = json_decode(trim((string) file_get_contents($fallbackFile)), true);
    @unlink($fallbackFile);
    expect($line['message'])->toBe('activity_log_write_failed')
        ->and($line['context']['entry']['route'])->toBe('/api/v1/rien')
        ->and($line['context']['entry']['result'])->toBe('not_found')
        ->and($line['context']['error'])->toContain('activity_logs');
});

describe('hors requêtes HTTP', function () {
    it('journalise un Job réussi et un Job en échec', function () {
        config(['queue.default' => 'sync']);

        dispatch(new SampleJob);
        expect(fn () => dispatch(new SampleJob(fails: true)))->toThrow(RuntimeException::class);

        $logs = ActivityLog::query()->where('action', 'job.SampleJob')->orderBy('id')->get();
        expect($logs)->toHaveCount(2)
            ->and($logs[0]->result->value)->toBe('success')
            ->and($logs[0]->actor_label)->toBe('system:queue')
            ->and($logs[1]->result->value)->toBe('error')
            ->and($logs[1]->error_code)->toBe('RuntimeException');
    });

    it('journalise une commande, son code de sortie, et ignore les boucles d\'infrastructure', function () {
        // Laravel n'émet les événements de commande que hors des tests unitaires : on les rétablit,
        // comme sous « php artisan ».
        $kernel = app(Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
        Artisan::command('essai:echec', fn (): int => 1);
        config(['activity.ignored_commands' => ['about']]);

        $this->artisan('logs:partitions')->assertSuccessful();
        $this->artisan('essai:echec')->assertExitCode(1);
        $this->artisan('about')->run();

        expect(ActivityLog::query()->where('action', 'command.logs:partitions')->sole())
            ->result->value->toBe('success')
            ->actor_type->toBe('system')
            ->actor_label->toBe('system:console')
            ->and(ActivityLog::query()->where('action', 'command.essai:echec')->sole()->error_code)->toBe('exit_1')
            ->and(ActivityLog::query()->where('action', 'command.about')->exists())->toBeFalse();
    });

    it('journalise une tâche planifiée réussie ou en échec', function () {
        $task = app(Schedule::class)->command('logs:partitions');

        event(new ScheduledTaskFinished($task, 1.234));
        event(new ScheduledTaskFailed($task, new LogicException('panne')));

        $logs = ActivityLog::query()->where('action', 'schedule.logs:partitions')->orderBy('id')->get();
        expect($logs)->toHaveCount(2)
            ->and($logs[0]->duration_ms)->toBe(1234)
            ->and($logs[0]->actor_label)->toBe('system:scheduler')
            ->and($logs[1]->result->value)->toBe('error')
            ->and($logs[1]->error_code)->toBe('LogicException');
    });
});
