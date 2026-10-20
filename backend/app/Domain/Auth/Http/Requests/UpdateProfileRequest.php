<?php

namespace App\Domain\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/me (F-AUTH-05) : prénom, nom, e-mail (facultatif, unique).
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['present', 'nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()?->getAuthIdentifier())],
        ];
    }
}
