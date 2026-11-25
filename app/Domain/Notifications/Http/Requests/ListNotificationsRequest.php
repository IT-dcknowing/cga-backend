<?php

namespace App\Domain\Notifications\Http\Requests;

use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/me/notifications : page, taille de page (limitée par pagination.max), non lues seulement.
 */
class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', DatabaseNotification::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) $settings->get('pagination.max')],
            'unread' => ['nullable', 'boolean'],
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(SettingsService $settings): int
    {
        return (int) ($this->validated('per_page') ?? $settings->get('pagination.default'));
    }

    public function unreadOnly(): bool
    {
        return filter_var($this->validated('unread') ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
