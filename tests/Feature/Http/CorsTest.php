<?php

/*
 * CORS limité aux origines déclarées (02-TRD §6.7, A02) : le SPA est servi sur le même domaine que
 * l'API ; sur le poste local, CORS_ALLOWED_ORIGINS nomme le serveur Vite (http://localhost:5173 dans
 * phpunit.xml). Avec les cookies, l'origine est toujours nommée, jamais « * ».
 */

it('autorise le SPA déclaré à appeler l\'API avec ses cookies', function () {
    $this->call('OPTIONS', '/api/health', server: [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-xsrf-token, content-type',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('ne donne aucune autorisation CORS à une autre origine', function () {
    // Avec une seule origine déclarée, c'est elle qui est renvoyée : le navigateur bloque l'autre.
    $response = $this->withHeaders(['Origin' => 'https://site-inconnu.example'])->getJson('/sanctum/csrf-cookie');

    expect($response->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('https://site-inconnu.example')
        ->not->toBe('*');
});

it('n\'autorise jamais toutes les origines', function () {
    expect(config('cors.allowed_origins'))->not->toContain('*')
        ->and(config('cors.allowed_origins_patterns'))->toBe([])
        ->and(config('cors.supports_credentials'))->toBeTrue();
});

it('lit les origines autorisées dans CORS_ALLOWED_ORIGINS, sans aucune par défaut', function () {
    expect(config('cors.allowed_origins'))->toBe(['http://localhost:5173'])
        ->and(file_get_contents(config_path('cors.php')))->toContain("env('CORS_ALLOWED_ORIGINS', '')");
});
