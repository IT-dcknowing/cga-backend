<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/*
 * Sanctum en mode SPA (06-IMPLEMENTATION-PLAN étape 0.3, 02-TRD §6.1, T-01) : session dans un
 * cookie HttpOnly, jeton CSRF obligatoire pour toute écriture, aucun jeton d'API.
 */

const SPA_ORIGIN = 'http://localhost:5173';

beforeEach(function () {
    Route::middleware('api')->post('api/v1/essai-ecriture', fn () => response()->noContent());
});

function fromSpa(): array
{
    return ['Origin' => SPA_ORIGIN, 'Referer' => SPA_ORIGIN.'/'];
}

function cookieNamed(TestResponse $response, string $name): Cookie
{
    $cookie = collect($response->headers->getCookies())->first(fn (Cookie $cookie): bool => $cookie->getName() === $name);
    expect($cookie)->not->toBeNull();

    return $cookie;
}

it('donne au SPA son jeton CSRF et une session dans un cookie HttpOnly, Secure et SameSite=Lax', function () {
    $response = $this->withHeaders(fromSpa())->get('/sanctum/csrf-cookie')->assertNoContent();

    $session = cookieNamed($response, config('session.cookie'));
    expect($session->isHttpOnly())->toBeTrue()
        ->and($session->isSecure())->toBeTrue()
        ->and($session->getSameSite())->toBe('lax');

    // Le jeton CSRF est lisible par le SPA, qui le renvoie dans l'en-tête X-XSRF-TOKEN.
    expect(cookieNamed($response, 'XSRF-TOKEN')->isHttpOnly())->toBeFalse();
});

/*
 * Laravel ne vérifie pas le jeton CSRF dans l'environnement « testing » : ces deux tests passent sous
 * un autre environnement pour l'éprouver réellement.
 */
it('refuse une écriture du SPA sans jeton CSRF', function () {
    app()->instance('env', 'recette');

    $this->withHeaders(fromSpa())->postJson('/api/v1/essai-ecriture')->assertStatus(419);
});

it('accepte une écriture du SPA qui renvoie son jeton CSRF', function () {
    app()->instance('env', 'recette');
    $csrf = $this->withHeaders(fromSpa())->get('/sanctum/csrf-cookie');

    $this->withHeaders([...fromSpa(), 'X-XSRF-TOKEN' => $csrf->getCookie('XSRF-TOKEN', decrypt: false)->getValue()])
        ->withCookie(config('session.cookie'), $csrf->getCookie(config('session.cookie'))->getValue())
        ->postJson('/api/v1/essai-ecriture')
        ->assertNoContent();
});

it('n\'ouvre pas de session pour un appel qui ne vient pas du SPA', function () {
    $this->withHeaders(['Origin' => 'https://site-inconnu.example'])
        ->postJson('/api/v1/essai-ecriture')
        ->assertNoContent()
        ->assertCookieMissing(config('session.cookie'));
});

it('n\'utilise aucun jeton d\'API', function () {
    expect(Schema::hasTable('personal_access_tokens'))->toBeFalse()
        ->and(config('sanctum.guard'))->toBe(['web']);
});
