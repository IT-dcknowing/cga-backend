<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/members/{member}/radiation/cancel : « Annuler la radiation » (RG-ADH-11), avec motif.
 */
class CancelRadiationRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('cancelRadiation', $this->member());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
