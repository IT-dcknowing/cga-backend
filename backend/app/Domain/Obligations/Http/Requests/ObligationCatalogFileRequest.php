<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Documents\Models\File;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Obligations\Support\CatalogFile;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/network/obligation-catalog/preview (fichier JSON de la version) et …/apply (fichier déjà
 * déposé à l'aperçu) : nouvelle version du catalogue national (RG-DATA-04).
 */
class ObligationCatalogFileRequest extends FormRequest
{
    /** Usage du fichier déposé à l'aperçu, dans son chemin de stockage. */
    public const FILE_TYPE = 'obligation_catalog';

    public function authorize(): bool
    {
        return Gate::allows('manageCatalog', ObligationType::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return $this->routeIs('network.obligation-catalog.apply')
            ? ['file_id' => ['required', 'string', 'ulid']]
            : ['file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)]];
    }

    public function upload(): UploadedFile
    {
        $file = $this->validated('file');

        return $file instanceof UploadedFile ? $file : throw new \UnexpectedValueException('Fichier non validé.');
    }

    /**
     * Contenu du fichier déposé, contrôlé : un catalogue JSON.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException contenu illisible
     */
    public function catalog(): array
    {
        return CatalogFile::decode($this->upload()->getContent());
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
            ->where('path', 'like', 'orgs/%/'.self::FILE_TYPE.'/%')
            ->firstOrFail();
    }
}
