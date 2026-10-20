<?php

namespace App\Domain\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/password/code (F-AUTH-03, étape 1) : téléphone ou e-mail du compte.
 */
class RequestPasswordCodeRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:120'],
        ];
    }
}
