<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\ChangePassword;
use App\Domain\Auth\Actions\ConfirmPhoneChange;
use App\Domain\Auth\Actions\RequestPhoneChangeCode;
use App\Domain\Auth\Http\Requests\ChangePasswordRequest;
use App\Domain\Auth\Http\Requests\ConfirmPhoneRequest;
use App\Domain\Auth\Http\Requests\RequestPhoneCodeRequest;
use App\Domain\Auth\Http\Requests\UpdatePreferencesRequest;
use App\Domain\Auth\Http\Resources\SessionResource;
use App\Domain\Auth\Support\AuthSession;
use App\Domain\Auth\Support\DevMessages;
use App\Domain\Notifications\Actions\SetNotificationPreferences;
use App\Domain\Notifications\Enums\PreferenceChannel;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Mon profil (F-AUTH-05) : mot de passe, téléphone confirmé par un code envoyé au nouveau numéro,
 * préférences SMS / WhatsApp (RG-NOT-04).
 */
class ProfileSecurityController extends BaseApiController
{
    /**
     * POST /api/v1/me/password
     */
    public function password(ChangePasswordRequest $request, ChangePassword $changePassword): Response|JsonResponse
    {
        return $this->handle(function () use ($request, $changePassword): Response {
            $changePassword->execute($this->currentUser(), (string) $request->validated('password'));

            return response()->noContent();
        }, action: 'auth.password.change');
    }

    /**
     * POST /api/v1/me/phone/code
     */
    public function phoneCode(RequestPhoneCodeRequest $request, RequestPhoneChangeCode $requestCode, DevMessages $devMessages): JsonResponse
    {
        return $this->handle(function () use ($request, $requestCode, $devMessages): JsonResponse {
            $issued = $requestCode->execute($this->currentUser(), (string) $request->validated('phone'));

            return new JsonResponse(['message' => __('auth.phone_code_sent'), ...$devMessages->payload($issued)], 202);
        }, action: 'auth.phone.code');
    }

    /**
     * POST /api/v1/me/phone
     */
    public function phone(ConfirmPhoneRequest $request, ConfirmPhoneChange $confirmPhone, AuthSession $session): SessionResource|JsonResponse
    {
        return $this->handle(function () use ($request, $confirmPhone, $session): SessionResource {
            $user = $confirmPhone->execute($this->currentUser(), (string) $request->validated('phone'), (string) $request->validated('code'));

            return new SessionResource($session->state($user));
        }, action: 'auth.phone.change');
    }

    /**
     * PATCH /api/v1/me/preferences
     */
    public function preferences(UpdatePreferencesRequest $request, SetNotificationPreferences $setPreferences, AuthSession $session): SessionResource|JsonResponse
    {
        return $this->handle(function () use ($request, $setPreferences, $session): SessionResource {
            $user = $this->currentUser();
            $setPreferences->execute($user, [
                PreferenceChannel::Sms->value => (bool) $request->validated('sms'),
                PreferenceChannel::Whatsapp->value => (bool) $request->validated('whatsapp'),
            ]);

            return new SessionResource($session->state($user->unsetRelation('notificationPreferences')));
        }, action: 'auth.preferences.update');
    }
}
