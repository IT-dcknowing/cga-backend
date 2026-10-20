<?php

namespace App\Domain\Auth\Http\Requests;

use App\Support\Phones\PhoneNormalizer;
use App\Support\Validation\Rules\AvailablePhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/me/phone/code (F-AUTH-05) : nouveau numéro, valide et libre.
 */
class RequestPhoneCodeRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones): array
    {
        return [
            'phone' => ['required', 'string', 'max:30', $phones->rule(), new AvailablePhone($phones, $this->user()?->getAuthIdentifier())],
        ];
    }
}
