<?php

namespace App\Domain\Referentials\Http\Controllers;

use App\Domain\Referentials\Actions\DeleteReferential;
use App\Domain\Referentials\Actions\SaveNationalReference;
use App\Domain\Referentials\Http\Requests\ListNationalReferencesRequest;
use App\Domain\Referentials\Http\Requests\NationalReferenceRequest;
use App\Domain\Referentials\Http\Resources\NationalReferenceResource;
use App\Domain\Referentials\Models\NationalReference;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/referentials/national-references : Références nationales annuelles (F-REF-04) — lecture dans tout espace, gestion avec le droit
 * referentials.manage.
 */
class NationalReferenceController extends BaseApiController
{
    public function index(ListNationalReferencesRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $items = QueryBuilder::for(NationalReference::query(), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-year')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return NationalReferenceResource::collection($items);
        }, action: 'referentials.national-references.index');
    }

    public function store(NationalReferenceRequest $request, SaveNationalReference $save): NationalReferenceResource|JsonResponse
    {
        return $this->handle(function () use ($request, $save): NationalReferenceResource {
            $item = $save->execute(new NationalReference, $request->validated(), $this->currentUser());

            return new NationalReferenceResource($item);
        }, action: 'referentials.national-references.store');
    }

    public function show(NationalReference $nationalReference): NationalReferenceResource|JsonResponse
    {
        return $this->handle(function () use ($nationalReference): NationalReferenceResource {
            Gate::authorize('view', $nationalReference);

            return new NationalReferenceResource($nationalReference);
        }, action: 'referentials.national-references.show');
    }

    public function update(NationalReferenceRequest $request, NationalReference $nationalReference, SaveNationalReference $save): NationalReferenceResource|JsonResponse
    {
        return $this->handle(function () use ($request, $nationalReference, $save): NationalReferenceResource {
            return new NationalReferenceResource($save->execute($nationalReference, $request->validated(), $this->currentUser()));
        }, action: 'referentials.national-references.update');
    }

    public function destroy(NationalReference $nationalReference, DeleteReferential $delete): Response|JsonResponse
    {
        return $this->handle(function () use ($nationalReference, $delete): Response {
            Gate::authorize('delete', $nationalReference);
            $delete->execute($nationalReference);

            return response()->noContent();
        }, action: 'referentials.national-references.destroy');
    }
}
