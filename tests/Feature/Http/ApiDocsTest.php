<?php

/*
 * Documentation OpenAPI générée par Scramble (06-IMPLEMENTATION-PLAN étape 0.3, 02-TRD §4.2) :
 * /docs/api et /docs/api.json, ouverts sur le poste local seulement (02-TRD §6.7, A02). Le frontend
 * en tire ses types (npm run gen:api).
 */

it('affiche la documentation de l\'API sur le poste local', function () {
    app()->instance('env', 'local');

    $this->get('/docs/api')->assertOk()->assertSee('CGA Connect');
});

it('publie le schéma OpenAPI des routes de l\'API', function () {
    app()->instance('env', 'local');

    $this->getJson('/docs/api.json')
        ->assertOk()
        ->assertJsonPath('info.title', 'CGA Connect — API')
        ->assertJsonStructure(['openapi', 'paths' => ['/health' => ['get']]]);
});

it('ferme la documentation hors du poste local', function (string $environment, string $path) {
    app()->instance('env', $environment);

    $this->get($path)->assertForbidden();
})->with(['recette', 'production', 'testing'])->with(['/docs/api', '/docs/api.json']);
