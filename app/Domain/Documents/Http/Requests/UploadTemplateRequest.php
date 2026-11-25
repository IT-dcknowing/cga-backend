<?php

namespace App\Domain\Documents\Http\Requests;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Rules\TemplateFileFormat;
use App\Domain\Documents\Support\TemplateInspector;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/platform/document-templates : dépôt d'une nouvelle version d'un modèle (F-ADM-02,
 * multipart) — type de document produit par la plateforme, numéro de version (unique pour ce type,
 * contrôlé par UploadTemplateVersion), fichier DOCX ou XLSX selon le type (type réel contrôlé, taille
 * files.max_upload_mb), note.
 */
class UploadTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', DocumentTemplate::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings, TemplateInspector $inspector, TemplateFileFormat $format): array
    {
        $types = array_values(array_filter(DocumentType::cases(), static fn (DocumentType $type): bool => $inspector->format($type) !== null));

        return [
            'type' => ['required', 'string', Rule::in(array_map(static fn (DocumentType $type): string => $type->value, $types))],
            'version' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z][0-9A-Za-z._-]*$/'],
            'file' => ['required', 'file', 'mimetypes:'.implode(',', TemplateFileFormat::MIME_TYPES), $format, 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function type(): DocumentType
    {
        return DocumentType::from((string) $this->validated('type'));
    }

    public function version(): string
    {
        return (string) $this->validated('version');
    }

    public function template(): UploadedFile
    {
        /** @var UploadedFile $file fichier exigé par les règles */
        $file = $this->validated('file');

        return $file;
    }

    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return is_string($notes) && $notes !== '' ? $notes : null;
    }
}
