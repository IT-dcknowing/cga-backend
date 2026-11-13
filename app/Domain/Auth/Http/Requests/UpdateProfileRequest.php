<?php

namespace App\Domain\Auth\Http\Requests;

use App\Domain\Users\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

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
            // E-mails stockés en minuscules (User::email) : l'unicité ignore donc la casse saisie.
            'email' => ['present', 'nullable', 'string', 'email', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                $taken = is_string($value) && User::query()
                    ->where('email', mb_strtolower(trim($value)))
                    ->whereKeyNot($this->user()?->getAuthIdentifier())
                    ->exists();

                if ($taken) {
                    $fail(__('validation.unique', ['attribute' => __('validation.attributes.email')]));
                }
            }],
        ];
    }
}
