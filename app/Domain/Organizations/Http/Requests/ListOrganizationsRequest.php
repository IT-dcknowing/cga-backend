<?php

namespace App\Domain\Organizations\Http\Requests;

use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Support\Http\ListRequest;
use App\Support\Http\Query\SearchFilter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/network/organizations : registre des CGA (F-ORG-01), filtres par statut, abonnement,
 * agrément et direction régionale.
 */
class ListOrganizationsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Organization::class);
    }

    protected function filters(): array
    {
        return [
            'search' => ['string', 'max:100'],
            'status' => [Rule::enum(OrganizationStatus::class)],
            'subscription_status' => [Rule::enum(SubscriptionStatus::class)],
            'agreement_status' => [Rule::enum(AgreementStatus::class)],
            'regional_directorate_id' => ['ulid'],
        ];
    }

    public function sorts(): array
    {
        return ['name', 'city', 'created_at'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [
            AllowedFilter::custom('search', new SearchFilter(['name', 'sigle', 'city', 'agreement_number'])),
            AllowedFilter::exact('status'),
            AllowedFilter::exact('subscription_status'),
            AllowedFilter::exact('agreement_status'),
            AllowedFilter::exact('regional_directorate_id'),
        ];
    }
}
