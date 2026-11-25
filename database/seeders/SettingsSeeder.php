<?php

namespace Database\Seeders;

use App\Domain\Platform\Models\Setting;
use App\Support\Config\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Valeur initiale des paramètres réseau et plateforme du registre config/cga.php, pour qu'ils soient
 * visibles et modifiables à l'écran (06-IMPLEMENTATION-PLAN étape 0.6). Une valeur déjà enregistrée
 * n'est jamais remplacée. Les paramètres propres à un CGA gardent la valeur du réseau tant qu'il ne
 * les a pas changés.
 */
class SettingsSeeder extends Seeder
{
    private const SEEDED_LEVELS = ['platform', 'network'];

    public function run(SettingsService $settings): void
    {
        foreach ($settings->definitions() as $key => $definition) {
            if (array_intersect((array) $definition['level'], self::SEEDED_LEVELS) === []) {
                continue;
            }

            $exists = Setting::query()->whereNull('organization_id')->where('key', $key)->exists();
            if ($exists) {
                continue;
            }

            $setting = new Setting;
            $setting->key = $key;
            $setting->value = $definition['default'];
            $setting->save();
        }
    }
}
