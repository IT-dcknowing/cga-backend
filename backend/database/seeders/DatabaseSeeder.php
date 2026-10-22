<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Données de base de toute installation (php artisan migrate --seed), et jeu de démonstration si
 * SEED_DEMO=true (poste de développement, recette).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlatformSeeder::class,
            SettingsSeeder::class,
            PermissionSeeder::class,
            ReferentialSeeder::class,
            TemplateSeeder::class,
        ]);

        if (config('cga.seed.demo.enabled') === true) {
            $this->call(DemoSeeder::class);
        }
    }
}
