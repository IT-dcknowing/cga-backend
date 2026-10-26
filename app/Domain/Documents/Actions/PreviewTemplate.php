<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\File;
use App\Domain\Documents\Samples\SampleDocumentData;
use App\Domain\Documents\Support\TemplateInspector;
use App\Domain\Documents\Support\VerificationCode;
use App\Domain\Documents\Templates\DocxTemplateRenderer;
use App\Domain\Reports\Workbook\OfficialWorkbookFiller;
use App\Domain\Users\Models\User;
use App\Support\Files\PdfConverter;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Str;

/**
 * Prévisualisation d'une version de modèle avec des données de test (F-ADM-02) : le modèle rempli avec
 * les données fictives des échantillons, converti en PDF par la chaîne de production, rangé comme
 * fichier de la plateforme (lien signé). Rien n'est émis : ni numéro, ni document, ni code de
 * vérification réel.
 */
class PreviewTemplate
{
    private const STORAGE_TYPE = 'template_previews';

    public function __construct(
        private readonly Repository $config,
        private readonly StorageService $storage,
        private readonly TemplateInspector $inspector,
        private readonly DocxTemplateRenderer $renderer,
        private readonly OfficialWorkbookFiller $workbook,
        private readonly PdfConverter $converter,
        private readonly SampleDocumentData $samples,
        private readonly VerificationCode $codes,
    ) {}

    /**
     * @throws BusinessRuleException template_variables_mismatch : variables à corriger d'abord
     */
    public function execute(DocumentTemplate $template, User $actor): File
    {
        $check = $this->inspector->check($template->type, $template->variables);
        if (! $check['valid']) {
            throw new BusinessRuleException('template_variables_mismatch', details: ['missing' => $check['missing'], 'unknown' => $check['unknown']]);
        }

        $type = $template->type->value;
        $format = (string) $this->inspector->format($template->type);
        /** @var array<string, mixed> $definition */
        $definition = (array) $this->config->get("documents.templates.{$type}", []);

        $work = $this->config->string('documents.work_path').'/'.Str::lower((string) Str::ulid());
        Filesystem::ensureDirectoryExists($work.'/images');
        try {
            $source = "{$work}/template.{$format}";
            Filesystem::put($source, $this->storage->contents($template->file));
            $filled = "{$work}/preview.{$format}";

            if ($format === 'xlsx') {
                $sample = $this->samples->workbook();
                $this->workbook->fill($source, $filled, $sample['values'], $sample['rows']);
            } else {
                $images = $this->samples->images($work.'/images', $this->codes->url('apercu'));
                $wanted = [...(array) ($definition['images'] ?? []), ...(array) ($definition['framed_images'] ?? [])];
                $this->renderer->render($source, $filled, $this->samples->values($type), array_intersect_key($images, array_flip($wanted)), $this->samples->rows($type));
            }

            $pdf = $this->converter->convert($filled);
        } finally {
            Filesystem::deleteDirectory($work);
        }

        return $this->storage->store($pdf, Str::slug($template->type->label().' v'.$template->version.' apercu').'.pdf', self::STORAGE_TYPE, null, $actor->id);
    }
}
