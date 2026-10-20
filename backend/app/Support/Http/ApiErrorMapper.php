<?php

namespace App\Support\Http;

use App\Domain\Audit\Enums\ActivityResult;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Http\Exceptions\ExternalServiceException;
use App\Support\Http\Exceptions\TooManyAttemptsException;
use App\Support\Logging\AlertCounter;
use App\Support\Logging\LogActivity;
use App\Support\Logging\RequestContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Traduit toute exception en réponse JSON normalisée (02-TRD §4.3, §4.4), pour handle() comme pour
 * le gestionnaire global, et note le résultat et le code d'erreur pour le journal d'activité. Aucun
 * détail technique n'est renvoyé au client ; un 403 incrémente le compteur d'alertes du jour.
 */
class ApiErrorMapper
{
    public const DENIED_ALERT = 'denied';

    public const DENIED_ALERTS_KEY = AlertCounter::PREFIX.self::DENIED_ALERT.':';

    public function __construct(
        private readonly AlertCounter $alerts,
        private readonly RequestContext $requestContext,
    ) {}

    public function render(Throwable $exception, Request $request): JsonResponse
    {
        [$status, $body, $result, $headers] = $this->map($exception);

        $request->attributes->set(LogActivity::RESULT, $result->value);
        $request->attributes->set(LogActivity::ERROR_CODE, $body['code'] ?? null);

        if ($result === ActivityResult::Denied && $status === 403) {
            $this->alerts->increment(self::DENIED_ALERT);
        }

        return new JsonResponse($body, $status, $headers);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: ActivityResult, 3: array<string, string>}
     */
    private function map(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof ValidationException => [
                $exception->status,
                ['message' => $exception->getMessage(), 'errors' => $exception->errors()],
                ActivityResult::ValidationFailed,
                [],
            ],
            $exception instanceof AuthenticationException => $this->error(401, 'unauthenticated', ActivityResult::Denied),
            $exception instanceof AuthorizationException,
            $exception instanceof AccessDeniedHttpException => $this->error(403, 'forbidden', ActivityResult::Denied),
            $exception instanceof ModelNotFoundException,
            $exception instanceof RecordsNotFoundException,
            $exception instanceof NotFoundHttpException => $this->error(404, 'not_found', ActivityResult::NotFound),
            $exception instanceof TooManyAttemptsException => [
                429,
                ['message' => $exception->getMessage(), 'code' => $exception->errorCode, 'details' => (object) $exception->details],
                ActivityResult::Throttled,
                ['Retry-After' => (string) $exception->retryAfterSeconds],
            ],
            $exception instanceof BusinessRuleException => [
                $exception->status,
                ['message' => $exception->getMessage(), 'code' => $exception->errorCode, 'details' => (object) $exception->details],
                ActivityResult::BusinessError,
                [],
            ],
            $exception instanceof ThrottleRequestsException => $this->error(429, 'throttled', ActivityResult::Throttled, $exception->getHeaders()),
            $exception instanceof TokenMismatchException => $this->error(419, 'csrf_mismatch', ActivityResult::Denied),
            $exception instanceof ExternalServiceException => $this->error(502, 'external_service_unavailable', ActivityResult::Error),
            $exception instanceof HttpExceptionInterface => $this->httpError($exception),
            default => $this->error(500, 'server_error', ActivityResult::Error, [], ['reference' => $this->requestContext->requestId() ?? '-']),
        };
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: ActivityResult, 3: array<string, string>}
     */
    private function httpError(HttpExceptionInterface $exception): array
    {
        $status = $exception->getStatusCode();
        $code = match ($status) {
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            419 => 'csrf_mismatch',
            429 => 'throttled',
            503 => 'service_unavailable',
            default => 'http_error',
        };

        /** @var array<string, string> $headers */
        $headers = $exception->getHeaders();

        return $this->error($status, $code, ActivityResult::fromStatus($status), $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $replace
     * @return array{0: int, 1: array<string, mixed>, 2: ActivityResult, 3: array<string, string>}
     */
    private function error(int $status, string $code, ActivityResult $result, array $headers = [], array $replace = []): array
    {
        return [$status, ['message' => __("errors.{$code}", $replace), 'code' => $code], $result, $headers];
    }
}
