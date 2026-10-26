<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\SelectSpace;
use App\Domain\Auth\Actions\UpdateProfile;
use App\Domain\Auth\Http\Requests\SelectSpaceRequest;
use App\Domain\Auth\Http\Requests\UpdateProfileRequest;
use App\Domain\Auth\Http\Resources\SessionResource;
use App\Domain\Auth\Support\AuthSession;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;

/**
 * Session de l'utilisateur connecté (02-TRD §6.2) : état, choix de l'espace (F-AUTH-04), profil
 * (F-AUTH-05).
 */
class MeController extends BaseApiController
{
    /**
     * GET /api/v1/me
     */
    public function show(AuthSession $session): SessionResource|JsonResponse
    {
        return $this->handle(fn (): SessionResource => new SessionResource($session->state($this->currentUser())), action: 'auth.me');
    }

    /**
     * PATCH /api/v1/me
     */
    public function update(UpdateProfileRequest $request, UpdateProfile $updateProfile, AuthSession $session): SessionResource|JsonResponse
    {
        return $this->handle(function () use ($request, $updateProfile, $session): SessionResource {
            /** @var array{first_name: string, last_name: string, email: string|null} $profile */
            $profile = $request->validated();
            $user = $updateProfile->execute($this->currentUser(), $profile);

            return new SessionResource($session->state($user));
        }, action: 'auth.profile.update');
    }

    /**
     * POST /api/v1/me/space
     */
    public function space(SelectSpaceRequest $request, SelectSpace $selectSpace, AuthSession $session): SessionResource|JsonResponse
    {
        return $this->handle(function () use ($request, $selectSpace, $session): SessionResource {
            $user = $this->currentUser();
            $membership = $selectSpace->execute($user, (string) $request->validated('membership_id'));
            $this->logSubject($membership);
            $session->enter($membership);

            return new SessionResource($session->state($user));
        }, action: 'auth.space');
    }
}
