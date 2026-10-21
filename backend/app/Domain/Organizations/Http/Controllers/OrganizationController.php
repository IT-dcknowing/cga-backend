<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Domain\Auth\Support\DevMessages;
use App\Domain\Members\Support\RegulatoryMemberCounts;
use App\Domain\Organizations\Actions\CreateCga;
use App\Domain\Organizations\Actions\UpdateCgaRegistration;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Http\Requests\CgaRegistrationRequest;
use App\Domain\Organizations\Http\Requests\ListOrganizationsRequest;
use App\Domain\Organizations\Http\Resources\OrganizationResource;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Actions\InviteCgaManager;
use App\Domain\Users\Http\Requests\InviteCgaManagerRequest;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/network/organizations : registre des CGA tenu par le CNCGA (F-ORG-01, 02, 04) — liste
 * filtrée, création, fiche détaillée avec son historique, modification de la fiche minimale,
 * ouverture de l'accès (invitation du gérant).
 */
class OrganizationController extends BaseApiController
{
    public function index(ListOrganizationsRequest $request, SettingsService $settings, RegulatoryMemberCounts $counts): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings, $counts): AnonymousResourceCollection {
            $organizations = QueryBuilder::for(Organization::query()->where('type', OrganizationType::Cga)->with('regionalDirectorate'), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('name')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());
            $counts->load($organizations->getCollection());

            return OrganizationResource::collection($organizations);
        }, action: 'organizations.index');
    }

    public function store(CgaRegistrationRequest $request, CreateCga $create): OrganizationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $create): OrganizationResource {
            $organization = $create->execute($request->validated());
            $this->logSubject($organization);

            return new OrganizationResource($organization->load(['regionalDirectorate', 'manager.user']));
        }, action: 'organizations.store');
    }

    public function show(Organization $organization): OrganizationResource|JsonResponse
    {
        return $this->handle(function () use ($organization): OrganizationResource {
            Gate::authorize('view', $organization);

            return new OrganizationResource($organization->load(['regionalDirectorate', 'statusEvents.actor', 'manager.user']));
        }, action: 'organizations.show');
    }

    public function update(CgaRegistrationRequest $request, Organization $organization, UpdateCgaRegistration $update): OrganizationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $organization, $update): OrganizationResource {
            $organization = $update->execute($organization, $request->validated());

            return new OrganizationResource($organization->load(['regionalDirectorate', 'statusEvents.actor', 'manager.user']));
        }, action: 'organizations.update');
    }

    public function inviteManager(InviteCgaManagerRequest $request, Organization $organization, InviteCgaManager $invite, DevMessages $devMessages): JsonResponse
    {
        return $this->handle(function () use ($request, $organization, $invite, $devMessages): JsonResponse {
            $invited = $invite->execute($organization, $request->identity(), $request->channel(), $this->currentUser());
            $this->logSubject($invited->user);

            return (new OrganizationResource($organization->load(['regionalDirectorate', 'statusEvents.actor', 'manager.user'])))
                ->additional($devMessages->invitation($invited->channel, $invited->user, $invited->link))
                ->response()
                ->setStatusCode(201);
        }, action: 'organizations.manager');
    }
}
