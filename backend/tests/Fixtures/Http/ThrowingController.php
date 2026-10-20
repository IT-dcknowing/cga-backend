<?php

namespace Tests\Fixtures\Http;

use App\Domain\Organizations\Models\Organization;
use App\Support\Http\BaseApiController;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Contrôleur de test : lève l'exception demandée dans handle() (02-TRD §4.4).
 */
class ThrowingController extends BaseApiController
{
    public function __invoke(string $type): JsonResponse
    {
        return $this->handle(fn (): JsonResponse => match ($type) {
            'ok' => new JsonResponse(['data' => 'ok']),
            'validation' => throw ValidationException::withMessages(['name' => ['Le champ nom est obligatoire.']]),
            'authorization' => throw new AuthorizationException('Accès refusé (détail interne)'),
            'model' => throw (new ModelNotFoundException)->setModel(Organization::class, ['01J0000000000000000000000X']),
            'http-not-found' => throw new NotFoundHttpException('Chemin interne /srv/secret'),
            'business' => throw new BusinessRuleException('space_required', details: ['hint' => 'me/space']),
            'throttle' => throw new ThrottleRequestsException('Trop', null, ['Retry-After' => '30']),
            'external' => throw new ExternalServiceException('sms', '{"error":"quota dépassé","token":"abc"}'),
            default => throw new RuntimeException('SQLSTATE[08006] host=10.0.0.5 password=secret'),
        }, action: 'essai.erreur');
    }
}
