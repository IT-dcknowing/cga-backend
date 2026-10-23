<?php

namespace App\Domain\Documents\Http\Requests;

use App\Domain\Documents\Enums\UploadedDocumentCategory;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/documents/upload : dépôt d'un document dans l'espace de l'adhérent
 * (F-DOC-05, multipart) — titre, catégorie, fichier PDF (type réel contrôlé, taille files.max_upload_mb).
 */
class UploadDocumentRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('produce', [GeneratedDocument::class, $this->member()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::enum(UploadedDocumentCategory::class)],
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function title(): string
    {
        return (string) $this->validated('title');
    }

    public function category(): UploadedDocumentCategory
    {
        return UploadedDocumentCategory::from((string) $this->validated('category'));
    }

    public function pdf(): UploadedFile
    {
        /** @var UploadedFile $file fichier exigé par les règles */
        $file = $this->validated('file');

        return $file;
    }
}
