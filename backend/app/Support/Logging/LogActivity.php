<?php

namespace App\Support\Logging;

use App\Domain\Audit\Enums\ActivityResult;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Journalise chaque requête, lectures comprises (02-TRD §6.5) : premier middleware de l'application,
 * il pose l'identifiant de corrélation X-Request-Id (repris dans la réponse, les erreurs 500 et les
 * journaux) et écrit la ligne d'activité après l'envoi de la réponse.
 */
class LogActivity
{
    public const ACTION = 'activity.action';

    public const RESULT = 'activity.result';

    public const ERROR_CODE = 'activity.error_code';

    /**
     * Utilisateur de l'action quand la session ne le dit plus (déconnexion).
     */
    public const USER_ID = 'activity.user_id';

    /**
     * Objet visé quand il n'est pas un paramètre de la route (espace choisi, invitation acceptée).
     */
    public const SUBJECT = 'activity.subject';

    /**
     * Acteur sans compte nommé par l'action (public:verification).
     */
    public const ACTOR_LABEL = 'activity.actor_label';

    private const STARTED_AT = 'activity.started_at';

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly Repository $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(RequestContext::REQUEST_ID_ATTRIBUTE, $this->requestId($request));
        $request->attributes->set(self::STARTED_AT, hrtime(true));

        $response = $next($request);
        $response->headers->set('X-Request-Id', (string) $request->attributes->get(RequestContext::REQUEST_ID_ATTRIBUTE));

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = $request->route();
        $routeName = is_object($route) ? $route->getName() : null;
        $uri = is_object($route) ? $route->uri() : ltrim($request->path(), '/');
        $subject = $this->subject($request);
        $result = $request->attributes->get(self::RESULT);
        $userId = $request->attributes->get(self::USER_ID);
        $actorLabel = $request->attributes->get(self::ACTOR_LABEL);

        $this->logger->record(
            (string) ($request->attributes->get(self::ACTION) ?? $routeName ?? "{$request->method()} /{$uri}"),
            is_string($result) ? ActivityResult::from($result) : ActivityResult::fromStatus($response->getStatusCode()),
            [
                'method' => $request->method(),
                'route' => mb_substr('/'.ltrim($uri, '/'), 0, 200),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject === null ? null : mb_substr((string) $subject->getKey(), 0, 26),
                'params' => $this->params($request),
                'http_status' => $response->getStatusCode(),
                'duration_ms' => $this->durationMs($request),
                'error_code' => $request->attributes->get(self::ERROR_CODE),
                ...(is_string($userId) ? ['user_id' => $userId] : []),
                ...(is_string($actorLabel) ? ['actor_label' => $actorLabel] : []),
            ],
        );
    }

    private function requestId(Request $request): string
    {
        $incoming = $request->headers->get('X-Request-Id');

        return is_string($incoming) && preg_match($this->config->string('activity.request_id_pattern'), $incoming) === 1
            ? $incoming
            : strtolower((string) Str::ulid());
    }

    /**
     * Objet désigné par l'action, sinon premier modèle lié par la route.
     */
    private function subject(Request $request): ?Model
    {
        $explicit = $request->attributes->get(self::SUBJECT);
        if ($explicit instanceof Model) {
            return $explicit;
        }

        $route = $request->route();
        if (! is_object($route)) {
            return null;
        }

        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof Model) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Paramètres de l'URL et du corps ; un fichier envoyé n'est décrit que par son nom et sa taille.
     *
     * @return array<string, mixed>
     */
    private function params(Request $request): array
    {
        $params = [...$request->query->all(), ...$request->getPayload()->all()];

        foreach ($request->files->all() as $field => $file) {
            $params[$field] = $file instanceof UploadedFile
                ? ['file' => $file->getClientOriginalName(), 'size' => $file->getSize()]
                : ['files' => is_array($file) ? count($file) : 1];
        }

        return $params;
    }

    private function durationMs(Request $request): ?int
    {
        $startedAt = $request->attributes->get(self::STARTED_AT);

        return is_int($startedAt) ? intdiv(hrtime(true) - $startedAt, 1_000_000) : null;
    }
}
