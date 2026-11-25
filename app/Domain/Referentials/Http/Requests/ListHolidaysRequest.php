<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\Holiday;
use App\Support\Http\ListRequest;
use App\Support\Http\Query\SearchFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/referentials/holidays : Jours fériés (RG-OBL-06), filtres et tris permis.
 */
class ListHolidaysRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Holiday::class);
    }

    protected function filters(): array
    {
        return [
            'year' => ['integer', 'digits:4'],
            'search' => ['string', 'max:100'],
        ];
    }

    public function sorts(): array
    {
        return ['date', 'label'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [
            AllowedFilter::callback('year', static fn (Builder $query, mixed $year) => $query->whereYear('date', (int) $year)),
            AllowedFilter::custom('search', new SearchFilter(['label'])),
        ];
    }
}
