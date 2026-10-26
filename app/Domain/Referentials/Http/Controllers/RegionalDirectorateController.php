<?php

namespace App\Domain\Referentials\Http\Controllers;

use App\Domain\Referentials\Actions\DeleteReferential;
use App\Domain\Referentials\Actions\SaveReferential;
use App\Domain\Referentials\Http\Requests\ListRegionalDirectoratesRequest;
use App\Domain\Referentials\Http\Requests\RegionalDirectorateRequest;
use App\Domain\Referentials\Http\Resources\RegionalDirectorateResource;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/referentials/regional-directorates : Directions régionales (F-REF-02) — lecture dans tout espace, gestion avec le droit
 * referentials.manage.
 */
class RegionalDirectorateController extends BaseApiController
{
    public function index(ListRegionalDirectoratesRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $items = QueryBuilder::for(RegionalDirectorate::query(), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('name')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return RegionalDirectorateResource::collection($items);
        }, action: 'referentials.regional-directorates.index');
    }

    public function store(RegionalDirectorateRequest $request, SaveReferential $save): RegionalDirectorateResource|JsonResponse
    {
        return $this->handle(function () use ($request, $save): RegionalDirectorateResource {
            $item = $save->execute(new RegionalDirectorate, $request->validated());

            return new RegionalDirectorateResource($item);
        }, action: 'referentials.regional-directorates.store');
    }

    public function show(RegionalDirectorate $regionalDirectorate): RegionalDirectorateResource|JsonResponse
    {
        return $this->handle(function () use ($regionalDirectorate): RegionalDirectorateResource {
            Gate::authorize('view', $regionalDirectorate);

            return new RegionalDirectorateResource($regionalDirectorate);
        }, action: 'referentials.regional-directorates.show');
    }

    public function update(RegionalDirectorateRequest $request, RegionalDirectorate $regionalDirectorate, SaveReferential $save): RegionalDirectorateResource|JsonResponse
    {
        return $this->handle(function () use ($request, $regionalDirectorate, $save): RegionalDirectorateResource {
            return new RegionalDirectorateResource($save->execute($regionalDirectorate, $request->validated()));
        }, action: 'referentials.regional-directorates.update');
    }

    public function destroy(RegionalDirectorate $regionalDirectorate, DeleteReferential $delete): Response|JsonResponse
    {
        return $this->handle(function () use ($regionalDirectorate, $delete): Response {
            Gate::authorize('delete', $regionalDirectorate);
            $delete->execute($regionalDirectorate);

            return response()->noContent();
        }, action: 'referentials.regional-directorates.destroy');
    }
}
