<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Composers\DocumentComposer;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Events\DocumentReady;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\VerificationCode;
use App\Domain\Documents\Templates\DocxTemplateRenderer;
use App\Domain\Documents\Templates\IncompleteTemplateDataException;
use App\Support\Files\PdfConverter;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LogicException;

/**
 * Production des fichiers d'un document (02-TRD §8.2), appelée par le Job GenerateDocument : modèle de
 * la ligne rempli avec les données de son composeur et le QR code de vérification, DOCX converti en
 * PDF, empreinte SHA-256 du PDF, DOCX et PDF rangés par StorageService
 * (orgs/{organisation}/documents/{année}/…), puis document valide et événement DocumentReady. Les
 * fichiers intermédiaires vivent dans un dossier de travail jetable. Un document qui n'est plus
 * « en production » n'est pas refait.
 */
class RenderDocument
{
    private const STORAGE_TYPE = 'documents';

    public function __construct(
        private readonly Repository $config,
        private readonly Container $container,
        private readonly StorageService $storage,
        private readonly DocxTemplateRenderer $renderer,
        private readonly PdfConverter $converter,
        private readonly VerificationCode $codes,
    ) {}

    /**
     * @throws IncompleteTemplateDataException variable du modèle sans valeur
     * @throws ExternalServiceException conversion PDF impossible
     */
    public function execute(GeneratedDocument $document): GeneratedDocument
    {
        if ($document->status !== DocumentStatus::Generating) {
            return $document;
        }

        $template = DocumentTemplate::query()->with('file')->findOrFail($document->template_id);
        $content = $this->composer($document)->compose($document);

        $work = $this->config->string('documents.work_path').'/'.Str::lower((string) Str::ulid());
        File::ensureDirectoryExists($work);
        try {
            $templatePath = $work.'/template.docx';
            File::put($templatePath, $this->storage->contents($template->file));

            $images = [];
            foreach ([...$content->images, 'qr_code' => $this->codes->qrCode((string) $document->verification_token)] as $variable => $image) {
                if (in_array($variable, $template->variables, true)) {
                    $images[$variable] = $work.'/'.$variable.($this->storage->detectMimeType($image) === 'image/png' ? '.png' : '.jpg');
                    File::put($images[$variable], $image);
                }
            }

            $docxPath = $work.'/document.docx';
            $this->renderer->render($templatePath, $docxPath, $content->values, $images, $content->rows);
            $pdf = $this->converter->convert($docxPath);
            $docx = File::get($docxPath);
        } finally {
            File::deleteDirectory($work);
        }

        return DB::transaction(function () use ($document, $docx, $pdf): GeneratedDocument {
            $document = GeneratedDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== DocumentStatus::Generating) {
                return $document;
            }

            $name = Str::slug($document->type->label().' '.($document->number ?? $document->id).($document->version > 1 ? ' v'.$document->version : ''));
            $docxFile = $this->storage->store($docx, $name.'.docx', self::STORAGE_TYPE, $document->organization_id, $document->generated_by);
            $pdfFile = $this->storage->store($pdf, $name.'.pdf', self::STORAGE_TYPE, $document->organization_id, $document->generated_by);

            $document->docx_file_id = $docxFile->id;
            $document->pdf_file_id = $pdfFile->id;
            $document->sha256 = $pdfFile->sha256;
            $document->status = DocumentStatus::Valid;
            $document->save();

            DocumentReady::dispatch($document);

            return $document;
        });
    }

    /**
     * @throws LogicException type sans composeur déclaré dans config/documents.php
     */
    private function composer(GeneratedDocument $document): DocumentComposer
    {
        $class = $this->config->get("documents.templates.{$document->type->value}.composer");
        $composer = is_string($class) ? $this->container->make($class) : null;

        return $composer instanceof DocumentComposer ? $composer : throw new LogicException("Aucun composeur pour le type {$document->type->value}.");
    }
}
