<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Domain\Organizations\Actions\CreateCga;
use App\Domain\Organizations\Actions\UpdateCgaRegistration;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Http\Requests\CgaRegistrationRequest;
use App\Domain\Organizations\Http\Requests\ListOrganizationsRequest;
use App\Domain\Organizations\Http\Resources\OrganizationResource;
use App\Domain\Organizations\Models\Organization;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/network/organizations : registre des CGA tenu par le CNCGA (F-ORG-01, 02, 04) — liste
 * filtrée, création, fiche détaillée avec son historique, modification de la fiche minimale.
 */
class OrganizationController extends BaseApiController
{
    public function index(ListOrganizationsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $organizations = QueryBuilder::for(Organization::query()->where('type', OrganizationType::Cga)->with('regionalDirectorate'), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('name')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return OrganizationResource::collection($organizations);
        }, action: 'organizations.index');
    }

    public function store(CgaRegistrationRequest $request, CreateCga $create): OrganizationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $create): OrganizationResource {
            $organization = $create->execute($request->validated());
            $this->logSubject($organization);

            return new OrganizationResource($organization->load('regionalDirectorate'));
        }, action: 'organizations.store');
    }

    public function show(Organization $organization): OrganizationResource|JsonResponse
    {
        return $this->handle(function () use ($organization): OrganizationResource {
            Gate::authorize('view', $organization);

            return new OrganizationResource($organization->load(['regionalDirectorate', 'statusEvents.actor']));
        }, action: 'organizations.show');
    }

    public function update(CgaRegistrationRequest $request, Organization $organization, UpdateCgaRegistration $update): OrganizationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $organization, $update): OrganizationResource {
            $organization = $update->execute($organization, $request->validated());

            return new OrganizationResource($organization->load(['regionalDirectorate', 'statusEvents.actor']));
        }, action: 'organizations.update');
    }
}
