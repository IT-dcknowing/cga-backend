<?php

namespace App\Domain\Imports\Http\Requests;

use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Models\ImportBatch;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use UnexpectedValueException;

/**
 * POST /api/v1/network/imports/cgas : fichier Excel du registre des CGA, rempli d'après le modèle
 * (F-IMP-01), au plus files.max_upload_mb (type lu dans le contenu).
 */
class StartCgaImportRequest extends FormRequest
{
    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function authorize(): bool
    {
        return Gate::allows('create', [ImportBatch::class, ImportType::Cgas]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:'.self::XLSX, 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function spreadsheet(): UploadedFile
    {
        $file = $this->validated('file');

        return $file instanceof UploadedFile ? $file : throw new UnexpectedValueException('Fichier non validé.');
    }
}
