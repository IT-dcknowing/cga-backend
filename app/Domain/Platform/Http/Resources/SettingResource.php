<?php

namespace App\Domain\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;

/**
 * Paramètre de l'espace (02-TRD §7.5) : clé, groupe, libellé et aide (lang/fr/settings.php), type, valeur
 * en vigueur au réseau, valeur par défaut du registre, valeur propre enregistrée ou non, droit de la
 * modifier. Jamais de secret : le registre n'en contient pas (identifiants dans l'écran « Envois »).
 *
 * @property array{key: string, type: string, levels: list<string>, value: mixed, default: mixed, overridden: bool, can_update: bool} $resource
 */
class SettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $setting = $this->resource;
        $group = explode('.', $setting['key'])[0];
        // Les clés contiennent des points : libellés et aides se lisent dans leur tableau.
        $labels = (array) __('settings.labels');
        $help = (array) __('settings.help');

        return [
            'key' => $setting['key'],
            'group' => $group,
            'group_label' => Lang::has("settings.groups.{$group}") ? (string) __("settings.groups.{$group}") : $group,
            'label' => is_string($labels[$setting['key']] ?? null) ? $labels[$setting['key']] : $setting['key'],
            'help' => is_string($help[$setting['key']] ?? null) ? $help[$setting['key']] : null,
            'type' => $setting['type'],
            'levels' => $setting['levels'],
            'value' => $setting['value'],
            'default' => $setting['default'],
            'is_default' => ! $setting['overridden'],
            'can' => ['update' => $setting['can_update']],
        ];
    }
}
