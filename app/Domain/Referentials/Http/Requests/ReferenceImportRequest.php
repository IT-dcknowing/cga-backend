<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Documents\Models\File;
use App\Domain\Imports\Http\Requests\StartCgaImportRequest;
use App\Domain\Referentials\Actions\ImportReferenceData;
use App\Domain\Referentials\ReferenceData\ImportMode;
use App\Domain\Referentials\ReferenceData\ReferenceDataset;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

/**
 * POST /api/v1/reference-data/{dataset}/preview (fichier Excel et mode) et …/apply (fichier déjà
 * déposé à l'aperçu, et mode) : remplacement ou mise à jour d'un jeu de référence (RG-DATA-01).
 */
class ReferenceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', $this->dataset());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        $file = $this->routeIs('reference-data.apply')
            ? ['file_id' => ['required', 'string', 'ulid']]
            : ['file' => ['required', 'file', 'mimetypes:'.StartCgaImportRequest::XLSX, 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)]];

        return [
            ...$file,
            'mode' => ['required', Rule::enum(ImportMode::class)],
        ];
    }

    public function mode(): ImportMode
    {
        return ImportMode::from((string) $this->validated('mode'));
    }

    public function spreadsheet(): UploadedFile
    {
        $file = $this->validated('file');

        return $file instanceof UploadedFile ? $file : throw new UnexpectedValueException('Fichier non validé.');
    }

    /**
     * Fichier déposé à l'aperçu, par l'organisation de l'espace actif.
     *
     * @throws ModelNotFoundException fichier inconnu, d'une autre organisation ou d'un autre usage
     */
    public function storedFile(TenantContext $context): File
    {
        return File::query()
            ->whereKey((string) $this->validated('file_id'))
            ->where('organization_id', $context->organizationId())
            ->where('path', 'like', 'orgs/%/'.ImportReferenceData::FILE_TYPE.'/%')
            ->firstOrFail();
    }

    /**
     * @throws AuthorizationException route sans jeu
     */
    public function dataset(): ReferenceDataset
    {
        $dataset = $this->route('dataset');

        return $dataset instanceof ReferenceDataset ? $dataset : throw new AuthorizationException;
    }
}
