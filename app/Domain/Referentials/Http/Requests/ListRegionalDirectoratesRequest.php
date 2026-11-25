<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Support\Http\ListRequest;
use App\Support\Http\Query\SearchFilter;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/referentials/regional-directorates : Directions régionales (F-REF-02), filtres et tris permis.
 */
class ListRegionalDirectoratesRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', RegionalDirectorate::class);
    }

    protected function filters(): array
    {
        return [
            'search' => ['string', 'max:100'],
            'active' => ['in:true,false,1,0'],
        ];
    }

    public function sorts(): array
    {
        return ['code', 'name'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [
            AllowedFilter::custom('search', new SearchFilter(['code', 'name'])),
            AllowedFilter::exact('active'),
        ];
    }
}
