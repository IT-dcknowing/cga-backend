<?php

namespace App\Console\Commands;

use App\Domain\Documents\Samples\SampleDocumentData;
use App\Domain\Documents\Templates\DocxTemplateRenderer;
use App\Domain\Reports\Workbook\OfficialWorkbookFiller;
use App\Support\Files\PdfConverter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Échantillons des modèles de documents (06-IMPLEMENTATION-PLAN étape 0.11) : chaque modèle rempli
 * avec des données fictives, en DOCX ou XLSX et en PDF par la chaîne de production (LibreOffice), pour
 * le contrôle visuel de la QA dans Word, Excel et LibreOffice (docs/decisions/0001).
 */
class GenerateDocumentSamples extends Command
{
    protected $signature = 'documents:samples
        {--output= : dossier de sortie (par défaut storage/app/private/samples)}
        {--without-pdf : ne pas convertir en PDF}';

    protected $description = 'Produit les échantillons des modèles de documents (DOCX, XLSX, PDF) avec des données fictives';

    public function handle(DocxTemplateRenderer $renderer, OfficialWorkbookFiller $workbook, PdfConverter $pdf, SampleDocumentData $data): int
    {
        $output = (string) ($this->option('output') ?: storage_path('app/private/samples'));
        File::ensureDirectoryExists($output.'/images');

        try {
            $images = $data->images($output.'/images', rtrim((string) config('app.url'), '/').'/verifier/exemple');
            $rows = [];

            foreach ((array) config('documents.templates') as $type => $template) {
                $started = hrtime(true);
                $file = "{$output}/{$type}.{$template['format']}";

                if ($template['format'] === 'docx') {
                    $renderer->render($template['path'], $file, $data->values($type), array_intersect_key($images, array_flip([...$template['images'], ...($template['framed_images'] ?? [])])), $data->rows($type));
                } else {
                    $sample = $data->workbook();
                    $workbook->fill($template['path'], $file, $sample['values'], $sample['rows']);
                }

                if (! $this->option('without-pdf')) {
                    file_put_contents("{$output}/{$type}.pdf", $pdf->convert($file));
                }

                $rows[] = [$template['label'], basename($file), number_format((hrtime(true) - $started) / 1e9, 1, ',', ' ').' s'];
            }
        } catch (Throwable $exception) {
            Log::error('Échantillons de documents : échec.', ['exception' => $exception]);

            throw $exception;
        }

        $this->table(['Modèle', 'Fichier', 'Durée (avec PDF)'], $rows);
        $this->info("Échantillons dans {$output}");

        return self::SUCCESS;
    }
}
