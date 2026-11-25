<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/cga/members/{member}/history : historique du dossier (F-ADH-03), paginé (02-TRD §4.3),
 * pour qui peut voir le dossier.
 */
class ListMemberHistoryRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('view', $this->member());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) $settings->get('pagination.max')],
        ];
    }

    public function perPage(SettingsService $settings): int
    {
        return (int) ($this->validated('per_page') ?? $settings->get('pagination.default'));
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
