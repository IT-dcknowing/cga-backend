<?php

namespace App\Domain\Documents\Templates;

use App\Domain\Reports\Charts\ChartFactory;
use App\Domain\Reports\Charts\ReportChart;
use finfo;
use GdImage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use ZipArchive;

/**
 * Remplit un modèle DOCX balisé ${…} (02-TRD §8.1, PhpWord TemplateProcessor) : lignes répétées
 * (cloneRow), graphiques natifs (rapport modernisé, décision T-21), images et valeurs, échappées pour
 * le XML. Une image est soit insérée à la place de sa
 * variable (signature, cachet, QR), soit « encadrée » : le modèle contient une image neutre
 * word/media/{variable}.jpeg|png dans un cadre fixe (logo de l'en-tête), remplacée par l'image donnée,
 * ajustée au cadre sans déformation. Un modèle dont une variable reste sans valeur est refusé : aucun
 * document ne part avec « ${…} » visible.
 */
class DocxTemplateRenderer
{
    private const FRAMED = '#^word/media/(?<variable>[a-z0-9_]+)\.(?<extension>jpe?g|png)$#';

    public function __construct(
        private readonly Repository $config,
        private readonly ChartFactory $charts,
    ) {}

    /**
     * @param  array<string, string>  $values  variable => texte
     * @param  array<string, string>  $images  variable => chemin d'une image PNG ou JPEG
     * @param  array<string, list<array<string, string>>>  $rows  variable de ligne => lignes (variable => texte)
     * @param  array<string, ReportChart>  $charts  variable => graphique, à la place du paragraphe de la variable
     *
     * @throws IncompleteTemplateDataException
     */
    public function render(string $templatePath, string $outputPath, array $values, array $images = [], array $rows = [], array $charts = []): void
    {
        $framed = $this->framedImages($templatePath);
        $missingFrames = array_diff(array_keys($framed), array_keys($images));
        if ($missingFrames !== []) {
            throw new IncompleteTemplateDataException(array_values($missingFrames));
        }

        // PhpWord nomme ses fichiers temporaires d'images sans les rendre uniques (image_rId14_document.png) :
        // un dossier temporaire par rendu évite que deux workers ne se les prennent.
        $temporary = $this->config->string('documents.temporary_path').'/'.Str::lower((string) Str::ulid());
        File::ensureDirectoryExists($temporary);
        Settings::setTempDir($temporary);
        Settings::setOutputEscapingEnabled(true);

        try {
            $processor = new TemplateProcessor($templatePath);

            foreach ($rows as $rowVariable => $lines) {
                $processor->cloneRowAndSetValues($rowVariable, $lines);
            }

            foreach ($charts as $variable => $chart) {
                $processor->setChart($variable, $this->charts->make($chart));
            }

            foreach (array_diff_key($images, $framed) as $variable => $path) {
                $size = (array) $this->config->get("documents.images.{$variable}", []);
                $processor->setImageValue($variable, ['path' => $path, 'width' => $size['width'] ?? 120, 'height' => $size['height'] ?? 120, 'ratio' => true]);
            }

            $processor->setValues($values);

            $missing = array_values(array_unique($processor->getVariables()));
            if ($missing !== []) {
                throw new IncompleteTemplateDataException($missing);
            }

            $processor->saveAs($outputPath);
        } finally {
            File::deleteDirectory($temporary);
            Settings::setTempDir(sys_get_temp_dir());
        }

        $this->replaceFramedImages($outputPath, $framed, $images);
        if ($charts !== []) {
            $this->colorCharts($outputPath);
        }
    }

    /**
     * Variables d'un modèle (contrôle d'un modèle déposé, F-ADM-02).
     *
     * @return list<string>
     */
    public function variables(string $templatePath): array
    {
        $variables = array_values(array_unique((new TemplateProcessor($templatePath))->getVariables()));
        sort($variables);

        return $variables;
    }

    /**
     * Images encadrées du modèle : variable => entrée du fichier DOCX.
     *
     * @return array<string, string>
     */
    public function framedImages(string $templatePath): array
    {
        $zip = $this->open($templatePath);
        $framed = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match(self::FRAMED, $entry, $match) === 1 && preg_match('/^image\d+$/', $match['variable']) !== 1) {
                $framed[$match['variable']] = $entry;
            }
        }
        $zip->close();

        return $framed;
    }

    /**
     * Couleur de chaque série des graphiques, d'après la palette (ChartFactory::colorSeries).
     */
    private function colorCharts(string $documentPath): void
    {
        $zip = $this->open($documentPath);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match('#^word/charts/chart\d+\.xml$#', $entry) === 1) {
                $zip->addFromString($entry, $this->charts->colorSeries((string) $zip->getFromName($entry)));
            }
        }
        $zip->close();
    }

    /**
     * @param  array<string, string>  $framed
     * @param  array<string, string>  $images
     */
    private function replaceFramedImages(string $documentPath, array $framed, array $images): void
    {
        if ($framed === []) {
            return;
        }

        $zip = $this->open($documentPath);
        foreach ($framed as $variable => $entry) {
            $placeholder = (string) $zip->getFromName($entry);
            $zip->addFromString($entry, $this->fit((string) file_get_contents($images[$variable]), $placeholder, $entry));
        }
        $zip->close();
    }

    /**
     * L'image, réduite pour tenir dans le cadre de l'image neutre, centrée sur fond blanc.
     */
    private function fit(string $image, string $placeholder, string $entry): string
    {
        $readable = in_array((new finfo(FILEINFO_MIME_TYPE))->buffer($image), ['image/png', 'image/jpeg'], true);
        $frame = getimagesizefromstring($placeholder);
        $source = $readable ? imagecreatefromstring($image) : false;
        if ($frame === false || $source === false) {
            throw new RuntimeException("Image illisible pour {$entry}.");
        }

        [$frameWidth, $frameHeight] = $frame;
        $scale = min($frameWidth / imagesx($source), $frameHeight / imagesy($source));
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));

        $canvas = imagecreatetruecolor($frameWidth, $frameHeight);
        if (! $canvas instanceof GdImage) {
            throw new RuntimeException("Cadre impossible à créer pour {$entry}.");
        }
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, intdiv($frameWidth - $width, 2), intdiv($frameHeight - $height, 2), 0, 0, $width, $height, imagesx($source), imagesy($source));

        ob_start();
        str_ends_with($entry, '.png') ? imagepng($canvas) : imagejpeg($canvas, null, 92);

        return (string) ob_get_clean();
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Document illisible : {$path}");
        }

        return $zip;
    }
}
