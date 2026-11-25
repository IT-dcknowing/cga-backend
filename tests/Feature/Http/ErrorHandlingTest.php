<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Support\Http\ApiErrorMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Http\ThrowingController;

/*
 * try/catch obligatoire par BaseApiController::handle() (02-TRD §4.4) : chaque type d'exception donne
 * le bon code HTTP, la bonne réponse, aucun détail technique, et une ligne de journal.
 */

beforeEach(function () {
    Route::middleware('api')->get('api/v1/essai-erreur/{type}', ThrowingController::class);
});

function activityOf(TestResponse $response): ActivityLog
{
    return ActivityLog::query()->where('request_id', $response->headers->get('X-Request-Id'))->sole();
}

it('laisse passer la réponse d\'une action réussie et la journalise sous son nom', function () {
    $response = $this->getJson('/api/v1/essai-erreur/ok')->assertOk()->assertExactJson(['data' => 'ok']);

    expect(activityOf($response))
        ->action->toBe('essai.erreur')
        ->result->value->toBe('success')
        ->http_status->toBe(200);
});

it('traduit chaque exception selon le 02-TRD §4.4', function (string $type, int $status, ?string $code, string $result) {
    $response = $this->getJson("/api/v1/essai-erreur/{$type}")->assertStatus($status);

    expect($response->json('code'))->toBe($code)
        ->and(activityOf($response))
        ->result->value->toBe($result)
        ->error_code->toBe($code)
        ->http_status->toBe($status);
})->with([
    'validation' => ['validation', 422, null, 'validation_failed'],
    'autorisation' => ['authorization', 403, 'forbidden', 'denied'],
    'modèle introuvable' => ['model', 404, 'not_found', 'not_found'],
    'adresse introuvable' => ['http-not-found', 404, 'not_found', 'not_found'],
    'règle métier' => ['business', 409, 'space_required', 'business_error'],
    'trop de requêtes' => ['throttle', 429, 'throttled', 'throttled'],
    'service externe' => ['external', 502, 'external_service_unavailable', 'error'],
    'erreur inattendue' => ['boom', 500, 'server_error', 'error'],
]);

it('renvoie les erreurs de validation au format de Laravel', function () {
    $this->getJson('/api/v1/essai-erreur/validation')
        ->assertExactJson(['message' => 'Le champ nom est obligatoire.', 'errors' => ['name' => ['Le champ nom est obligatoire.']]]);
});

it('renvoie le code, le message et le détail d\'une règle métier', function () {
    $this->getJson('/api/v1/essai-erreur/business')
        ->assertExactJson(['message' => 'Choisissez d\'abord un espace de travail.', 'code' => 'space_required', 'details' => ['hint' => 'me/space']]);
});

it('garde l\'en-tête Retry-After d\'une limitation de débit', function () {
    $this->getJson('/api/v1/essai-erreur/throttle')->assertHeader('Retry-After', '30');
});

it('ne révèle jamais de détail technique au client', function (string $type) {
    $response = $this->getJson("/api/v1/essai-erreur/{$type}");

    expect($response->getContent())->not->toContain('secret')
        ->not->toContain('10.0.0.5')
        ->not->toContain('quota')
        ->not->toContain('/srv/')
        ->not->toContain('détail interne')
        ->not->toContain('Exception');
})->with(['authorization', 'http-not-found', 'external', 'boom']);

it('donne la référence de la requête dans une erreur 500, et la signale au journal d\'erreurs', function () {
    Log::spy();

    $response = $this->getJson('/api/v1/essai-erreur/boom');

    $reference = $response->headers->get('X-Request-Id');
    expect($response->json('message'))->toBe("Une erreur est survenue (réf. {$reference}).");
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'SQLSTATE')
        && ($context['request_id'] ?? null) === $reference);
});

it('signale une panne d\'un service externe avec la réponse du fournisseur', function () {
    Log::spy();

    $this->getJson('/api/v1/essai-erreur/external')->assertStatus(502);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => ($context['service'] ?? null) === 'sms'
        && str_contains((string) ($context['provider_response'] ?? ''), 'quota'));
});

it('compte les refus du jour pour les alertes', function () {
    $key = ApiErrorMapper::DENIED_ALERTS_KEY.now()->toDateString();

    $this->getJson('/api/v1/essai-erreur/authorization');
    $this->getJson('/api/v1/essai-erreur/authorization');

    expect((int) Cache::get($key))->toBe(2);
});

describe('gestionnaire global, même format', function () {
    it('refuse une route protégée sans connexion (401)', function () {
        Route::middleware(['api', 'auth'])->get('api/v1/essai-protegee', fn () => 'ok');

        $response = $this->getJson('/api/v1/essai-protegee')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Connectez-vous pour continuer.', 'code' => 'unauthenticated']);
        expect(activityOf($response)->result->value)->toBe('denied');
    });

    it('répond 404 not_found à une adresse inconnue', function () {
        $response = $this->getJson('/api/v1/adresse-inconnue')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Élément introuvable.', 'code' => 'not_found']);
        expect(activityOf($response)->route)->toBe('/api/v1/adresse-inconnue');
    });

    it('répond 405 à une méthode non prévue', function () {
        $this->postJson('/api/health')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
    });

    it('répond 419 csrf_mismatch à une écriture du SPA sans jeton CSRF', function () {
        app()->instance('env', 'recette');
        Route::middleware('api')->post('api/v1/essai-ecriture', fn () => response()->noContent());

        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'])
            ->postJson('/api/v1/essai-ecriture')
            ->assertStatus(419)
            ->assertJsonPath('code', 'csrf_mismatch');
    });

    it('répond 429 throttled quand la limite de débit est atteinte', function () {
        Route::middleware(['api', 'throttle:1,1'])->get('api/v1/essai-limite', fn () => 'ok');

        $this->getJson('/api/v1/essai-limite')->assertOk();
        $this->getJson('/api/v1/essai-limite')->assertStatus(429)->assertJsonPath('code', 'throttled')->assertHeader('Retry-After');
    });
});
