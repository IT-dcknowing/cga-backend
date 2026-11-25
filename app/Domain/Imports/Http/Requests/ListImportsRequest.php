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
 * actif, d'un type de l'espace (registre des CGA au réseau ; portefeuille par défaut au CGA, ou reprise
 * d'historique avec `filter[type]=revenues|tax_payments`, F-IMP-03), les plus récents d'abord, filtrés
 * par avancement. Le droit est vérifié sur le type demandé, une fois la requête validée.
 */
class ListImportsRequest extends ListRequest
{
    public function authorize(): bool
    {
        foreach ($this->spaceTypes() as $type) {
            if (Gate::allows('viewAny', [ImportBatch::class, $type])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Type demandé (`filter[type]`), sinon le premier type de l'espace.
     */
    public function importType(): ImportType
    {
        $type = $this->validated('filter.type');

        return is_string($type) ? ImportType::from($type) : $this->spaceTypes()[0];
    }

    protected function filters(): array
    {
        return [
            'type' => [Rule::in(array_map(static fn (ImportType $type): string => $type->value, $this->spaceTypes()))],
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

    /**
     * Types d'import de l'espace de la route.
     *
     * @return non-empty-list<ImportType>
     */
    private function spaceTypes(): array
    {
        return str_starts_with((string) $this->route()?->getName(), 'cga.')
            ? [ImportType::Members, ImportType::Revenues, ImportType::TaxPayments]
            : [ImportType::Cgas];
    }
}
