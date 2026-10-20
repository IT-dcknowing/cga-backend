<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Le SPA s'authentifie par le cookie de session de Sanctum, avec le jeton CSRF (02-TRD §6.1).
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API seulement (02-TRD §3) : toute erreur est rendue en JSON, jamais en page HTML.
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);

        // Les erreurs sont aussi envoyées à Sentry quand SENTRY_LARAVEL_DSN est renseigné (02-TRD §12.3).
        Integration::handles($exceptions);
    })->create();
