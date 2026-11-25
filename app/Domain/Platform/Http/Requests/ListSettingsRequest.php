<?php

namespace App\Domain\Platform\Http\Requests;

use App\Domain\Platform\Models\Setting;
use App\Domain\Platform\Support\SettingsCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/network/settings et /api/v1/platform/settings : paramètres de l'espace, filtrés par groupe
 * (`group=report` : décalages de relance, échéance réglementaire, export de compatibilité, règle
 * « augmentée »…), parmi les groupes de l'espace.
 */
class ListSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', [Setting::class, $this->space()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsCatalog $catalog): array
    {
        return [
            'group' => ['nullable', 'string', Rule::in($catalog->groups($this->space()))],
        ];
    }

    public function space(): string
    {
        return $this->routeIs('platform.settings.*') ? SettingsCatalog::PLATFORM : SettingsCatalog::NETWORK;
    }

    public function group(): ?string
    {
        $group = $this->validated('group');

        return is_string($group) ? $group : null;
    }
}
