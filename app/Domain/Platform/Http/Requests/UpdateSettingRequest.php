<?php

namespace App\Domain\Platform\Http\Requests;

use App\Domain\Platform\Models\Setting;
use App\Domain\Platform\Support\SettingsCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /api/v1/{network|platform}/settings/{settingKey} : nouvelle valeur d'un paramètre de l'espace,
 * contrôlée ensuite par les règles du registre (config/cga.php). Une clé inconnue de l'espace répond 404.
 */
class UpdateSettingRequest extends FormRequest
{
    public function authorize(SettingsCatalog $catalog): bool
    {
        // Clé inconnue de l'espace : le contrôleur répond 404, sans révéler les droits.
        return ! $catalog->has($this->space(), $this->settingKey()) || Gate::allows('update', [Setting::class, $this->space(), $this->settingKey()]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['value' => ['present']];
    }

    public function space(): string
    {
        return $this->routeIs('platform.settings.*') ? SettingsCatalog::PLATFORM : SettingsCatalog::NETWORK;
    }

    public function settingKey(): string
    {
        return (string) $this->route('settingKey');
    }

    public function value(): mixed
    {
        return $this->validated('value');
    }
}
