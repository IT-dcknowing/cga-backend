<?php

namespace App\Domain\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/login (F-AUTH-01) : téléphone ou e-mail, et mot de passe.
 */
class LoginRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
