<?php

namespace App\Domain\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/me/space (F-AUTH-04) : le rattachement choisi.
 */
class SelectSpaceRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'membership_id' => ['required', 'string', 'ulid'],
        ];
    }
}
