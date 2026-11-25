<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\TaxOffice;
use App\Support\Http\ListRequest;
use App\Support\Http\Query\SearchFilter;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/referentials/tax-offices : Centres des impôts (F-REF-02), filtres et tris permis.
 */
class ListTaxOfficesRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', TaxOffice::class);
    }

    protected function filters(): array
    {
        return [
            'search' => ['string', 'max:100'],
            'active' => ['in:true,false,1,0'],
            'regional_directorate_id' => ['ulid'],
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
            AllowedFilter::exact('regional_directorate_id'),
        ];
    }
}
