<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Support\Ncc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/cga/members/ncc-check?ncc= : contrôle à la volée du NCC saisi à l'inscription
 * (F-ADH-02, RG-ADH-05).
 */
class NccCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [Member::class, Portfolio::Cga]) || Gate::allows('create', [Member::class, Portfolio::Cabinet]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'ncc' => ['required', 'string', 'max:20'],
        ];
    }

    public function ncc(): string
    {
        return Ncc::normalize((string) $this->validated('ncc'));
    }
}
