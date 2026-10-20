<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\RequestPasswordResetCode;
use App\Domain\Auth\Actions\ResetPassword;
use App\Domain\Auth\Http\Requests\RequestPasswordCodeRequest;
use App\Domain\Auth\Http\Requests\ResetPasswordRequest;
use App\Domain\Auth\Support\DevMessages;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Mot de passe oublié par code (F-AUTH-03). La demande de code répond toujours la même chose, que
 * le compte existe ou non (A07).
 */
class PasswordResetController extends BaseApiController
{
    /**
     * POST /api/v1/auth/password/code
     */
    public function requestCode(RequestPasswordCodeRequest $request, RequestPasswordResetCode $requestCode, DevMessages $devMessages): JsonResponse
    {
        return $this->handle(function () use ($request, $requestCode, $devMessages): JsonResponse {
            $issued = $requestCode->execute((string) $request->validated('identifier'));

            return new JsonResponse(['message' => __('auth.code_sent'), ...$devMessages->payload($issued)], 202);
        }, action: 'auth.password.code');
    }

    /**
     * POST /api/v1/auth/password/reset
     */
    public function reset(ResetPasswordRequest $request, ResetPassword $resetPassword): Response|JsonResponse
    {
        return $this->handle(function () use ($request, $resetPassword): Response {
            $resetPassword->execute((string) $request->validated('identifier'), (string) $request->validated('code'), (string) $request->validated('password'));

            return response()->noContent();
        }, action: 'auth.password.reset');
    }
}
