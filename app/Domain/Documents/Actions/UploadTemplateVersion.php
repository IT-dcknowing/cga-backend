<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Support\TemplateInspector;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Dépôt d'une nouvelle version d'un modèle de document (F-ADM-02, RG-DATA-03) par l'administrateur de
 * la plateforme : les variables du fichier (DOCX ou XLSX, type réel contrôlé par la validation) sont
 * relevées, le fichier rangé par StorageService ; la version reste inactive — les documents continuent
 * de sortir avec la version active jusqu'à l'activation de celle-ci. Un fichier illisible est refusé.
 */
class UploadTemplateVersion
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly TemplateInspector $inspector,
    ) {}

    /**
     * @throws ValidationException fichier illisible comme modèle, version déjà déposée pour ce type
     */
    public function execute(DocumentType $type, string $version, UploadedFile $upload, ?string $notes, User $actor): DocumentTemplate
    {
        try {
            $variables = $this->inspector->variables($type, (string) $upload->getRealPath());
        } catch (Throwable $exception) {
            Log::warning('Modèle de document illisible.', ['type' => $type->value, 'version' => $version, 'exception' => $exception->getMessage()]);

            throw ValidationException::withMessages(['file' => __('documents.template_unreadable')]);
        }

        return DB::transaction(function () use ($type, $version, $upload, $notes, $actor, $variables): DocumentTemplate {
            if (DocumentTemplate::query()->where('type', $type)->where('version', $version)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['version' => __('documents.template_version_exists', ['version' => $version])]);
            }

            $file = $this->storage->storeUpload($upload, 'templates', null, $actor->id);

            $template = new DocumentTemplate;
            $template->type = $type;
            $template->version = $version;
            $template->file_id = $file->id;
            $template->variables = $variables;
            $template->is_active = false;
            $template->notes = $notes;
            $template->save();

            return $template;
        });
    }
}
