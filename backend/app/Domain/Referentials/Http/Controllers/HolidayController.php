<?php

namespace App\Domain\Referentials\Http\Controllers;

use App\Domain\Referentials\Actions\DeleteReferential;
use App\Domain\Referentials\Actions\SaveReferential;
use App\Domain\Referentials\Http\Requests\HolidayRequest;
use App\Domain\Referentials\Http\Requests\ListHolidaysRequest;
use App\Domain\Referentials\Http\Resources\HolidayResource;
use App\Domain\Referentials\Models\Holiday;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * /api/v1/referentials/holidays : Jours fériés (RG-OBL-06) — lecture dans tout espace, gestion avec le droit
 * referentials.manage.
 */
class HolidayController extends BaseApiController
{
    public function index(ListHolidaysRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $items = QueryBuilder::for(Holiday::query(), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('date')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return HolidayResource::collection($items);
        }, action: 'referentials.holidays.index');
    }

    public function store(HolidayRequest $request, SaveReferential $save): HolidayResource|JsonResponse
    {
        return $this->handle(function () use ($request, $save): HolidayResource {
            $item = $save->execute(new Holiday, $request->validated());

            return new HolidayResource($item);
        }, action: 'referentials.holidays.store');
    }

    public function show(Holiday $holiday): HolidayResource|JsonResponse
    {
        return $this->handle(function () use ($holiday): HolidayResource {
            Gate::authorize('view', $holiday);

            return new HolidayResource($holiday);
        }, action: 'referentials.holidays.show');
    }

    public function update(HolidayRequest $request, Holiday $holiday, SaveReferential $save): HolidayResource|JsonResponse
    {
        return $this->handle(function () use ($request, $holiday, $save): HolidayResource {
            return new HolidayResource($save->execute($holiday, $request->validated()));
        }, action: 'referentials.holidays.update');
    }

    public function destroy(Holiday $holiday, DeleteReferential $delete): Response|JsonResponse
    {
        return $this->handle(function () use ($holiday, $delete): Response {
            Gate::authorize('delete', $holiday);
            $delete->execute($holiday);

            return response()->noContent();
        }, action: 'referentials.holidays.destroy');
    }
}
