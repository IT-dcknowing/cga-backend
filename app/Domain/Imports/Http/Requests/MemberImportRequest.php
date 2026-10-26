<?php

namespace App\Domain\Imports\Http\Requests;

use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Models\ImportBatch;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/imports/members[/preview] : portefeuille à analyser ou à importer (F-IMP-02) —
 * classeur Excel du modèle, numéro de départ de la séquence CGA Connect (RG-IMP-01).
 */
class MemberImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [ImportBatch::class, ImportType::Members]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:'.StartCgaImportRequest::XLSX, 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
            'start_number' => ['nullable', 'integer', 'min:1', 'max:99999999'],
        ];
    }

    public function spreadsheet(): UploadedFile
    {
        /** @var UploadedFile $file fichier exigé par les règles */
        $file = $this->validated('file');

        return $file;
    }

    public function startNumber(): ?int
    {
        $start = $this->validated('start_number');

        return $start === null ? null : (int) $start;
    }
}
