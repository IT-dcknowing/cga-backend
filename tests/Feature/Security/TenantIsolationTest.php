<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\CrossTenantObjects;

/*
 * Test de cloisonnement générique (06-IMPLEMENTATION-PLAN étape 0.8, 02-TRD §6.3, RG-SEC-02) — bloquant
 * en CI. Il parcourt toutes les routes /api/v1 qui désignent un objet : connecté comme gérant du CGA A
 * (tous les droits du centre), avec l'identifiant d'un objet du CGA B, chaque route doit répondre
 * 403 ou 404 (02-TRD §4.3 : 404 identique pour « inexistant » et « hors de votre organisation »).
 */

/**
 * Routes /api/v1 qui désignent au moins un objet par un paramètre d'URL.
 *
 * @return list<RouteDefinition>
 */
function routesWithParameters(?string $prefix = null): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RouteDefinition $route): bool => str_starts_with($route->uri(), $prefix ?? 'api/v1/') && $route->parameterNames() !== [] && ! designatesGlobalModel($route),
    ));
}

/**
 * La route désigne un objet national (référentiel), hors du cloisonnement entre CGA.
 */
function designatesGlobalModel(RouteDefinition $route): bool
{
    foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
        $class = $parameter->getType()?->getName();
        if ($class !== null && array_key_exists($class, CrossTenantObjects::globalModels())) {
            return true;
        }
    }

    return false;
}

/**
 * Valeur de chaque paramètre : l'identifiant d'un objet du CGA B.
 *
 * @return array<string, string>
 */
function parametersFromOtherTenant(RouteDefinition $route, Organization $other): array
{
    $models = [];
    foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
        $models[$parameter->getName()] = $parameter->getType()?->getName();
    }

    $values = [];
    foreach ($route->parameterNames() as $name) {
        $key = ($route->getName() ?? $route->uri()).':'.$name;
        $class = $models[$name] ?? null;

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            expect(CrossTenantObjects::nonTenantParameters())->toHaveKey($key, message: "Paramètre « {$name} » de {$route->uri()} : ni modèle, ni exception justifiée dans CrossTenantObjects.");
            $values[$name] = 'valeur-du-cga-b';

            continue;
        }

        $builder = CrossTenantObjects::builders()[$class] ?? null;
        expect($builder)->not->toBeNull("Ajouter à CrossTenantObjects la création d'un {$class} du CGA B (route {$route->uri()}).");
        $values[$name] = (string) $builder($other)->getRouteKey();
    }

    return $values;
}

/**
 * Appelle chaque route en gérant du CGA A avec les objets du CGA B, et exige 403 ou 404.
 *
 * @param  list<RouteDefinition>  $routes
 * @return int nombre de requêtes contrôlées
 */
function assertIsolated(array $routes): int
{
    $cgaA = Organization::factory()->create();
    $cgaB = Organization::factory()->create();
    $manager = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $cgaA->id]);

    $checked = 0;
    foreach ($routes as $route) {
        $uri = $route->uri();
        foreach (parametersFromOtherTenant($route, $cgaB) as $name => $value) {
            $uri = preg_replace('/\{'.$name.'\??\}/', $value, $uri);
        }

        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $status = test()->actingAs($manager->user)
                ->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'])
                ->withSession([TenantContext::SESSION_KEY => $manager->id])
                ->json($method, '/'.$uri)
                ->getStatusCode();

            expect($status)->toBeIn([403, 404], "{$method} /{$uri} a répondu {$status} à un utilisateur du CGA A pour un objet du CGA B.");
            $checked++;
        }
    }

    return $checked;
}

it('ne tient pour nationaux que des modèles sans organisation', function () {
    foreach (array_keys(CrossTenantObjects::globalModels()) as $model) {
        expect(class_uses_recursive($model))->not->toHaveKey(BelongsToOrganization::class)
            ->and(Schema::hasColumn((new $model)->getTable(), 'organization_id'))->toBeFalse("{$model} a une colonne organization_id : il relève du cloisonnement.");
    }
});

it('connaît les routes /api/v1 qui désignent un objet', function () {
    expect(routesWithParameters())->not->toBeEmpty();
});

it('RG-SEC-02 refuse à un CGA tout objet d\'un autre CGA, sur chaque route /api/v1', function () {
    expect(assertIsolated(routesWithParameters()))->toBeGreaterThan(0);
});

describe('contre-épreuves : le test attrape une fuite', function () {
    it('échoue pour une route qui renvoie l\'objet d\'un autre CGA', function () {
        Route::middleware(['api', 'auth', 'tenant'])->get('api/v1/essai-fuite/{organization}', fn (Organization $organization) => ['name' => $organization->name]);

        expect(fn () => assertIsolated(routesWithParameters('api/v1/essai-fuite')))->toThrow(ExpectationFailedException::class);
    });

    it('échoue pour une route qui attend un modèle que le test ne sait pas créer', function () {
        Route::middleware(['api', 'auth', 'tenant'])->get('api/v1/essai-inconnu/{log}', fn (AuditLog $log) => ['id' => $log->id]);

        expect(fn () => assertIsolated(routesWithParameters('api/v1/essai-inconnu')))
            ->toThrow(ExpectationFailedException::class, 'Ajouter à CrossTenantObjects');
    });

    it('échoue pour un paramètre qui n\'est pas un objet et n\'est pas justifié', function () {
        Route::middleware(['api', 'auth', 'tenant'])->get('api/v1/essai-texte/{code}', fn (string $code) => ['code' => $code]);

        expect(fn () => assertIsolated(routesWithParameters('api/v1/essai-texte')))
            ->toThrow(ExpectationFailedException::class, 'ni modèle, ni exception justifiée');
    });
});
