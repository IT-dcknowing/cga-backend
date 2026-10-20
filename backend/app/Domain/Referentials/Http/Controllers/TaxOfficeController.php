<?php

namespace App\Domain\Referentials\Http\Controllers;

use App\Domain\Referentials\Actions\DeleteReferential;
use App\Domain\Referentials\Actions\SaveReferential;
use App\Domain\Referentials\Http\Requests\ListTaxOfficesRequest;
use App\Domain\Referentials\Http\Requests\TaxOfficeRequest;
use App\Domain\Referentials\Http\Resources\TaxOfficeResource;
use App\Domain\Referentials\Models\TaxOffice;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/referentials/tax-offices : Centres des impôts (F-REF-02) — lecture dans tout espace, gestion avec le droit
 * referentials.manage.
 */
class TaxOfficeController extends BaseApiController
{
    public function index(ListTaxOfficesRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $items = QueryBuilder::for(TaxOffice::query()->with('regionalDirectorate'), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('name')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return TaxOfficeResource::collection($items);
        }, action: 'referentials.tax-offices.index');
    }

    public function store(TaxOfficeRequest $request, SaveReferential $save): TaxOfficeResource|JsonResponse
    {
        return $this->handle(function () use ($request, $save): TaxOfficeResource {
            $item = $save->execute(new TaxOffice, $request->validated());

            return new TaxOfficeResource($item->load('regionalDirectorate'));
        }, action: 'referentials.tax-offices.store');
    }

    public function show(TaxOffice $taxOffice): TaxOfficeResource|JsonResponse
    {
        return $this->handle(function () use ($taxOffice): TaxOfficeResource {
            Gate::authorize('view', $taxOffice);

            return new TaxOfficeResource($taxOffice->load('regionalDirectorate'));
        }, action: 'referentials.tax-offices.show');
    }

    public function update(TaxOfficeRequest $request, TaxOffice $taxOffice, SaveReferential $save): TaxOfficeResource|JsonResponse
    {
        return $this->handle(function () use ($request, $taxOffice, $save): TaxOfficeResource {
            return new TaxOfficeResource($save->execute($taxOffice, $request->validated())->load('regionalDirectorate'));
        }, action: 'referentials.tax-offices.update');
    }

    public function destroy(TaxOffice $taxOffice, DeleteReferential $delete): Response|JsonResponse
    {
        return $this->handle(function () use ($taxOffice, $delete): Response {
            Gate::authorize('delete', $taxOffice);
            $delete->execute($taxOffice);

            return response()->noContent();
        }, action: 'referentials.tax-offices.destroy');
    }
}
