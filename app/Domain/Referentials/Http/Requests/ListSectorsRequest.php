<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Enums\SectorStatus;
use App\Domain\Referentials\Models\Sector;
use App\Support\Http\ListRequest;
use App\Support\Http\Query\SearchFilter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/referentials/sectors : Secteurs d'activité (F-REF-01, RG-REF-01), filtres et tris permis.
 */
class ListSectorsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Sector::class);
    }

    protected function filters(): array
    {
        return [
            'search' => ['string', 'max:100'],
            'status' => [Rule::enum(SectorStatus::class)],
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
            AllowedFilter::exact('status'),
        ];
    }
}
