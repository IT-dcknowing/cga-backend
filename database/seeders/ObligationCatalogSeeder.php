<?php

namespace Database\Seeders;

use App\Domain\Obligations\Actions\LoadObligationCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Catalogue national des obligations (F-OBL-01, étape 4.1) : référentiel de DC-KNOWING fourni en JSON
 * (`cga.seed.obligations.catalog_path`), chargé comme version provisoire (RG-DATA-02). Sans fichier,
 * le catalogue reste vide jusqu'à son import par l'écran « Données de référence » : aucune obligation
 * n'est inventée. Rejouable sans doublon.
 */
class ObligationCatalogSeeder extends Seeder
{
    public function __construct(private readonly LoadObligationCatalog $load) {}

    public function run(): void
    {
        $path = (string) config('cga.seed.obligations.catalog_path');

        if (! File::exists($path)) {
            $this->command->warn(__('obligations.catalog_missing', ['path' => $path]));

            return;
        }

        $catalog = json_decode(File::get($path), true);

        if (! is_array($catalog)) {
            throw new RuntimeException(__('obligations.catalog_unreadable', ['path' => $path]));
        }

        $count = $this->load->execute($catalog);
        $this->command->info(__('obligations.catalog_loaded', ['count' => $count, 'version' => $catalog['version']]));
    }
}
