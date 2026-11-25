<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\AcceptInvitation;
use App\Domain\Auth\Actions\SelectSpace;
use App\Domain\Auth\Http\Requests\AcceptInvitationRequest;
use App\Domain\Auth\Http\Resources\InvitationResource;
use App\Domain\Auth\Http\Resources\SessionResource;
use App\Domain\Auth\Support\AuthSession;
use App\Domain\Auth\Support\InvitationToken;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;

/**
 * Activation d'un accès par le lien d'invitation (F-AUTH-02, RG-ACC-03). Après l'activation, la
 * session est ouverte dans l'espace de l'invitation.
 */
class InvitationController extends BaseApiController
{
    /**
     * GET /api/v1/auth/invitations/{jeton}
     */
    public function show(InvitationToken $invitationToken): InvitationResource|JsonResponse
    {
        return $this->handle(function () use ($invitationToken): InvitationResource {
            $this->logSubject($invitationToken->invitation);

            return new InvitationResource($invitationToken->invitation);
        }, action: 'auth.invitations.show');
    }

    /**
     * POST /api/v1/auth/invitations/{jeton}/accept
     */
    public function accept(AcceptInvitationRequest $request, InvitationToken $invitationToken, AcceptInvitation $acceptInvitation, SelectSpace $selectSpace, AuthSession $session): SessionResource|JsonResponse
    {
        return $this->handle(function () use ($request, $invitationToken, $acceptInvitation, $selectSpace, $session): SessionResource {
            $this->logSubject($invitationToken->invitation);

            $membership = $acceptInvitation->execute(
                $invitationToken->invitation,
                (string) $request->validated('password'),
                (bool) $request->validated('sms_opt_in', false),
                (bool) $request->validated('whatsapp_opt_in', false),
            );
            $user = $membership->user;

            $session->open($user);
            $session->enter($selectSpace->execute($user, $membership->id));

            return new SessionResource($session->state($user));
        }, action: 'auth.invitations.accept');
    }
}
