<?php

namespace App\Domain\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/v1/auth/invitations/{jeton}/accept (F-AUTH-02) : mot de passe, conditions d'utilisation
 * acceptées, et pour un adhérent les choix SMS / WhatsApp (non renseignés : refusés).
 */
class AcceptInvitationRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255', Password::defaults()],
            'accepted_terms' => ['accepted'],
            'sms_opt_in' => ['sometimes', 'boolean'],
            'whatsapp_opt_in' => ['sometimes', 'boolean'],
        ];
    }
}
