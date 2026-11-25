<?php

namespace App\Domain\Referentials\Http\Controllers;

use App\Domain\Referentials\Actions\DeleteReferential;
use App\Domain\Referentials\Actions\SaveReferential;
use App\Domain\Referentials\Http\Requests\CommuneRequest;
use App\Domain\Referentials\Http\Requests\ListCommunesRequest;
use App\Domain\Referentials\Http\Resources\CommuneResource;
use App\Domain\Referentials\Models\Commune;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/referentials/communes : Communes (F-REF-02) — lecture dans tout espace, gestion avec le droit
 * referentials.manage.
 */
class CommuneController extends BaseApiController
{
    public function index(ListCommunesRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $items = QueryBuilder::for(Commune::query(), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('name')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return CommuneResource::collection($items);
        }, action: 'referentials.communes.index');
    }

    public function store(CommuneRequest $request, SaveReferential $save): CommuneResource|JsonResponse
    {
        return $this->handle(function () use ($request, $save): CommuneResource {
            $item = $save->execute(new Commune, $request->validated());

            return new CommuneResource($item);
        }, action: 'referentials.communes.store');
    }

    public function show(Commune $commune): CommuneResource|JsonResponse
    {
        return $this->handle(function () use ($commune): CommuneResource {
            Gate::authorize('view', $commune);

            return new CommuneResource($commune);
        }, action: 'referentials.communes.show');
    }

    public function update(CommuneRequest $request, Commune $commune, SaveReferential $save): CommuneResource|JsonResponse
    {
        return $this->handle(function () use ($request, $commune, $save): CommuneResource {
            return new CommuneResource($save->execute($commune, $request->validated()));
        }, action: 'referentials.communes.update');
    }

    public function destroy(Commune $commune, DeleteReferential $delete): Response|JsonResponse
    {
        return $this->handle(function () use ($commune, $delete): Response {
            Gate::authorize('delete', $commune);
            $delete->execute($commune);

            return response()->noContent();
        }, action: 'referentials.communes.destroy');
    }
}
