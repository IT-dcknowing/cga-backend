<?php

namespace App\Domain\Referentials\Http\Controllers;

use App\Domain\Referentials\Actions\CreateSector;
use App\Domain\Referentials\Actions\DeleteReferential;
use App\Domain\Referentials\Actions\SaveReferential;
use App\Domain\Referentials\Http\Requests\ListSectorsRequest;
use App\Domain\Referentials\Http\Requests\SectorRequest;
use App\Domain\Referentials\Http\Resources\SectorResource;
use App\Domain\Referentials\Models\Sector;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/referentials/sectors : Secteurs d'activité (F-REF-01, RG-REF-01) — lecture dans tout espace, gestion avec le droit
 * referentials.manage.
 */
class SectorController extends BaseApiController
{
    public function index(ListSectorsRequest $request, SettingsService $settings, TenantContext $context): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings, $context): AnonymousResourceCollection {
            $items = QueryBuilder::for(Sector::query()->visibleIn($context), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('name')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return SectorResource::collection($items);
        }, action: 'referentials.sectors.index');
    }

    public function store(SectorRequest $request, CreateSector $save): SectorResource|JsonResponse
    {
        return $this->handle(function () use ($request, $save): SectorResource {
            /** @var array{code: string, name: string} $attributes */
            $attributes = $request->validated();
            $item = $save->execute($attributes, $this->currentUser());

            return new SectorResource($item);
        }, action: 'referentials.sectors.store');
    }

    public function show(Sector $sector, TenantContext $context): SectorResource|JsonResponse
    {
        return $this->handle(function () use ($sector, $context): SectorResource {
            Gate::authorize('view', $sector);
            // Secteur proposé par un autre CGA : introuvable dans cet espace (RG-REF-01).
            if (! Sector::query()->visibleIn($context)->whereKey($sector->id)->exists()) {
                throw (new ModelNotFoundException)->setModel(Sector::class, [$sector->id]);
            }

            return new SectorResource($sector);
        }, action: 'referentials.sectors.show');
    }

    public function update(SectorRequest $request, Sector $sector, SaveReferential $save): SectorResource|JsonResponse
    {
        return $this->handle(function () use ($request, $sector, $save): SectorResource {
            return new SectorResource($save->execute($sector, $request->validated()));
        }, action: 'referentials.sectors.update');
    }

    public function destroy(Sector $sector, DeleteReferential $delete): Response|JsonResponse
    {
        return $this->handle(function () use ($sector, $delete): Response {
            Gate::authorize('delete', $sector);
            $delete->execute($sector);

            return response()->noContent();
        }, action: 'referentials.sectors.destroy');
    }
}
