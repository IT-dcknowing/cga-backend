<?php

use App\Domain\Auth\Http\Middleware\ExpireSessions;
use App\Support\Http\ApiErrorMapper;
use App\Support\Logging\LogActivity;
use App\Support\Logging\RequestContext;
use App\Support\Tenancy\ResolveTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ValidateSignature;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Toute requête est journalisée, lectures comprises, avec son X-Request-Id (02-TRD §6.5).
        $middleware->prepend(LogActivity::class);

        // Le SPA s'authentifie par le cookie de session de Sanctum, avec le jeton CSRF (02-TRD §6.1).
        $middleware->statefulApi();

        // Déconnexion après inactivité (30 / 60 min selon l'espace) ou compte désactivé (02-TRD §6.1),
        // avant tout contrôle d'authentification.
        $middleware->api(append: [ExpireSessions::class]);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ExpireSessions::class);

        // Lien signé (02-TRD §6.4) : la signature est vérifiée avant de chercher l'objet, pour qu'un lien
        // forgé ne dise pas si l'identifiant existe.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ValidateSignature::class);

        // API seule (02-TRD §3) : un appel sans session reçoit 401 en JSON, jamais une redirection.
        $middleware->redirectGuestsTo(static fn (): ?string => null);

        // Routes métier : espace actif obligatoire, chargé dans TenantContext (02-TRD §6.2), avant de
        // chercher les objets de la route : un objet cloisonné n'est cherché que dans l'espace actif.
        $middleware->alias(['tenant' => ResolveTenant::class]);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API seulement (02-TRD §3) : toute erreur est rendue en JSON, au format de handle() (02-TRD §4.4).
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);
        $exceptions->render(fn (Throwable $exception, Request $request) => app(ApiErrorMapper::class)->render($exception, $request));

        // Contexte de chaque erreur signalée : requête, utilisateur, rattachement et organisation.
        $exceptions->context(fn (): array => array_filter([
            'request_id' => app(RequestContext::class)->requestId(),
            'user_id' => auth()->id(),
            'membership_id' => app(TenantContext::class)->has() ? app(TenantContext::class)->membership()->id : null,
            'organization_id' => app(TenantContext::class)->has() ? app(TenantContext::class)->organizationId() : null,
        ]));

        // Les erreurs sont aussi envoyées à Sentry quand SENTRY_LARAVEL_DSN est renseigné (02-TRD §12.3).
        Integration::handles($exceptions);
    })->create();
