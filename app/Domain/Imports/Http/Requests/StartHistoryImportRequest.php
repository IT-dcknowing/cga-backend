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
 * POST /api/v1/cga/imports/revenues et /tax-payments : fichier Excel d'historique (F-IMP-03), rempli
 * d'après le modèle, au plus files.max_upload_mb (type lu dans le contenu).
 */
class StartHistoryImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [ImportBatch::class, $this->importType()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:'.StartCgaImportRequest::XLSX, 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    /** Type d'historique désigné par la route. */
    public function importType(): ImportType
    {
        return $this->routeIs('cga.imports.revenues.*') ? ImportType::Revenues : ImportType::TaxPayments;
    }

    public function spreadsheet(): UploadedFile
    {
        $file = $this->validated('file');

        return $file instanceof UploadedFile ? $file : throw new UnexpectedValueException('Fichier non validé.');
    }
}
