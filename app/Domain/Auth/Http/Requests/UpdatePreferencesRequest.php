<?php

namespace App\Domain\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/me/preferences (F-AUTH-05, RG-NOT-04) : accepter ou non les SMS et WhatsApp non
 * essentiels.
 */
class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'sms' => ['required', 'boolean'],
            'whatsapp' => ['required', 'boolean'],
        ];
    }
}
