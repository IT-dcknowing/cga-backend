<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Données de base de toute installation (php artisan migrate --seed).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlatformSeeder::class,
            SettingsSeeder::class,
            PermissionSeeder::class,
        ]);
    }
}
