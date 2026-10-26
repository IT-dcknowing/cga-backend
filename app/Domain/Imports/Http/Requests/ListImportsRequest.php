<?php

namespace App\Domain\Imports\Http\Requests;

use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Models\ImportBatch;
use App\Support\Http\ListRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/network/imports et /api/v1/cga/imports : imports lancés par l'organisation de l'espace
 * actif, du type de l'espace (registre des CGA au réseau, portefeuille au CGA), les plus récents
 * d'abord, filtrés par avancement.
 */
class ListImportsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', [ImportBatch::class, $this->importType()]);
    }

    /**
     * Type d'import de l'espace de la route.
     */
    public function importType(): ImportType
    {
        return str_starts_with((string) $this->route()?->getName(), 'cga.') ? ImportType::Members : ImportType::Cgas;
    }

    protected function filters(): array
    {
        return [
            'type' => [Rule::enum(ImportType::class)],
            'status' => [Rule::enum(ImportStatus::class)],
        ];
    }

    public function sorts(): array
    {
        return ['created_at'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [AllowedFilter::exact('type'), AllowedFilter::exact('status')];
    }
}
