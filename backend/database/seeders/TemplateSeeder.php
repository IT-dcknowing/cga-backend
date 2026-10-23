<?php

namespace Database\Seeders;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Support\TemplateInspector;
use App\Support\Files\StorageService;
use Illuminate\Database\Seeder;

/**
 * Version 1 provisoire de chaque modèle de document (05-BACKEND-SCHEMA §18, 02-TRD §8.1) : les modèles
 * de la maquette nettoyés et balisés (resources/templates, config/documents.php), rangés par
 * StorageService et activés ; le CNCGA les valide puis les remplace (F-ADM-02). Les modèles qui
 * n'existent pas encore dans la maquette (reçu, facture, courrier de radiation…) s'ajoutent avec leurs
 * documents. Rejouable : un modèle déjà chargé n'est jamais remplacé.
 */
class TemplateSeeder extends Seeder
{
    public const VERSION = '1';

    public function run(StorageService $storage): void
    {
        foreach ((array) config('documents.templates') as $type => $template) {
            $documentType = DocumentType::from((string) $type);
            if (DocumentTemplate::query()->where('type', $documentType)->where('version', self::VERSION)->exists()) {
                continue;
            }

            $path = (string) $template['path'];
            $file = $storage->store((string) file_get_contents($path), basename($path), 'templates');

            $model = new DocumentTemplate;
            $model->type = $documentType;
            $model->version = self::VERSION;
            $model->file_id = $file->id;
            $model->variables = self::variables($template);
            $model->is_active = true;
            $model->notes = 'Modèle provisoire de la maquette, nettoyé de toute donnée réelle (RG-DATA-01).';
            $model->save();
        }
    }

    /**
     * Variables attendues par le modèle : textes, images, lignes répétées.
     *
     * @param  array<string, mixed>  $template
     * @return list<string>
     */
    public static function variables(array $template): array
    {
        return TemplateInspector::expected($template);
    }
}
