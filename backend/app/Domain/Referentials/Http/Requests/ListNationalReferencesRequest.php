<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\NationalReference;
use App\Support\Http\ListRequest;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/referentials/national-references : Références nationales annuelles (F-REF-04), filtres et tris permis.
 */
class ListNationalReferencesRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', NationalReference::class);
    }

    protected function filters(): array
    {
        return [
            'year' => ['integer', 'digits:4'],
        ];
    }

    public function sorts(): array
    {
        return ['year'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [
            AllowedFilter::exact('year'),
        ];
    }
}
