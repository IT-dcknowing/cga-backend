<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\Commune;
use App\Support\Http\ListRequest;
use App\Support\Http\Query\SearchFilter;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/referentials/communes : Communes (F-REF-02), filtres et tris permis.
 */
class ListCommunesRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Commune::class);
    }

    protected function filters(): array
    {
        return [
            'search' => ['string', 'max:100'],
            'regional_directorate_id' => ['ulid'],
        ];
    }

    public function sorts(): array
    {
        return ['name'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [
            AllowedFilter::custom('search', new SearchFilter(['name'])),
            AllowedFilter::exact('regional_directorate_id'),
        ];
    }
}
