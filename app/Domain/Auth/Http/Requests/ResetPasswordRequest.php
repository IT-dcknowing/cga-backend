<?php

namespace App\Domain\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/v1/auth/password/reset (F-AUTH-03, étape 2) : identifiant, code reçu, nouveau mot de
 * passe (8 caractères au moins, hors des mots de passe courants).
 */
class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'digits:'.config('auth.code_digits')],
            'password' => ['required', 'string', 'max:255', Password::defaults()],
        ];
    }
}
