<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\AttemptLogin;
use App\Domain\Auth\Actions\SelectSpace;
use App\Domain\Auth\Http\Requests\LoginRequest;
use App\Domain\Auth\Http\Resources\SessionResource;
use App\Domain\Auth\Support\AuthSession;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Connexion et déconnexion du SPA (F-AUTH-01, 02-TRD §6.1). Un utilisateur à un seul espace y entre
 * directement ; avec plusieurs, le SPA ouvre le sélecteur d'espace (03-APPFLOW §1).
 */
class SessionController extends BaseApiController
{
    /**
     * POST /api/v1/auth/login
     */
    public function store(LoginRequest $request, AttemptLogin $login, SelectSpace $selectSpace, AuthSession $session): SessionResource|JsonResponse
    {
        return $this->handle(function () use ($request, $login, $selectSpace, $session): SessionResource {
            $user = $login->execute((string) $request->validated('identifier'), (string) $request->validated('password'), (string) $request->ip());
            $session->open($user);

            $state = $session->state($user);
            $only = $state->memberships->count() === 1 ? $state->memberships->first() : null;
            if ($only !== null) {
                $session->enter($selectSpace->execute($user, $only->id));
                $state = $session->state($user);
            }

            return new SessionResource($state);
        }, action: 'auth.login');
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function destroy(AuthSession $session): Response|JsonResponse
    {
        return $this->handle(function () use ($session): Response {
            $this->logUser($this->currentUser());
            $session->close();

            return response()->noContent();
        }, action: 'auth.logout');
    }
}
