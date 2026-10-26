<?php

namespace App\Domain\Users\Http\Requests;

use App\Domain\Users\Models\User;
use App\Support\Http\ListRequest;
use App\Support\Http\Query\SearchFilter;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/platform/network-users : utilisateurs du CNCGA, pour leurs habilitations (RG-ROLE-07),
 * filtrés par nom, téléphone ou e-mail.
 */
class ListNetworkUsersRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewNetworkUsers', User::class);
    }

    protected function filters(): array
    {
        return ['search' => ['string', 'max:100']];
    }

    public function sorts(): array
    {
        return ['last_name', 'first_name'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [AllowedFilter::custom('search', new SearchFilter(['first_name', 'last_name', 'phone', 'email']))];
    }
}
