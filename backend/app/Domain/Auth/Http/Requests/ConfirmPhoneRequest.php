<?php

namespace App\Domain\Auth\Http\Requests;

use App\Support\Phones\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/me/phone (F-AUTH-05) : nouveau numéro et code reçu sur ce numéro.
 */
class ConfirmPhoneRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones): array
    {
        return [
            'phone' => ['required', 'string', 'max:30', $phones->rule()],
            'code' => ['required', 'string', 'digits:'.config('auth.code_digits')],
        ];
    }
}
