<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * La collection Postman suit les routes de l'API : chaque route /api/v1 y a au moins une requête, et
 * aucune requête sur {{base_url}} ne vise une route qui n'existe plus (règle du projet : collection à
 * jour à chaque nouvelle API).
 */

const POSTMAN_COLLECTION = 'postman/CGA-Connect.postman_collection.json';

/**
 * Routes /api/v1, avec une expression qui accepte n'importe quelle valeur à la place des paramètres.
 *
 * @return list<array{method: string, uri: string, pattern: string}>
 */
function apiRoutesForPostman(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => Str::startsWith($route->uri(), 'api/v1/') && ! Str::startsWith($route->uri(), 'api/v1/essai'))
        ->flatMap(function (RouteDefinition $route): array {
            $uri = Str::after($route->uri(), 'api/v1/');
            $pattern = collect(explode('/', $uri))
                ->map(fn (string $segment): string => Str::startsWith($segment, '{') ? '[^/]+' : preg_quote($segment, '#'))
                ->implode('/');

            return collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->map(fn (string $method): array => ['method' => $method, 'uri' => $uri, 'pattern' => "#^{$pattern}$#"])
                ->all();
        })
        ->values()
        ->all();
}

/**
 * Requêtes de la collection qui visent l'API ({{base_url}}).
 *
 * @param  list<array<string, mixed>>|null  $items
 * @return list<array{name: string, method: string, path: string}>
 */
function postmanRequests(?array $items = null): array
{
    $items ??= json_decode((string) file_get_contents(base_path(POSTMAN_COLLECTION)), true, flags: JSON_THROW_ON_ERROR)['item'];

    return collect($items)
        ->flatMap(fn (array $item): array => isset($item['item'])
            ? postmanRequests($item['item'])
            : (($item['request']['url']['host'] ?? []) === ['{{base_url}}']
                ? [['name' => $item['name'], 'method' => $item['request']['method'], 'path' => implode('/', $item['request']['url']['path'])]]
                : []))
        ->values()
        ->all();
}

it('a au moins une requête pour chaque route de l\'API', function () {
    $requests = postmanRequests();

    $missing = collect(apiRoutesForPostman())
        ->reject(fn (array $route): bool => collect($requests)->contains(
            fn (array $request): bool => $request['method'] === $route['method'] && preg_match($route['pattern'], $request['path']) === 1,
        ))
        ->map(fn (array $route): string => "{$route['method']} {$route['uri']}")
        ->values()
        ->all();

    expect($missing)->toBe([], 'Ajoutez ces routes à '.POSTMAN_COLLECTION.'.');
});

it('ne garde aucune requête vers une route qui n\'existe plus', function () {
    $routes = apiRoutesForPostman();

    $orphans = collect(postmanRequests())
        ->reject(fn (array $request): bool => collect($routes)->contains(
            fn (array $route): bool => $route['method'] === $request['method'] && preg_match($route['pattern'], $request['path']) === 1,
        ))
        ->map(fn (array $request): string => "{$request['name']} ({$request['method']} {$request['path']})")
        ->values()
        ->all();

    expect($orphans)->toBe([], 'Ces requêtes de '.POSTMAN_COLLECTION.' visent une route qui n\'existe plus.');
});
