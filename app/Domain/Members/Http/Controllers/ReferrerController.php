<?php

namespace App\Domain\Members\Http\Controllers;

use App\Domain\Members\Actions\DeleteReferrer;
use App\Domain\Members\Actions\SaveReferrer;
use App\Domain\Members\Http\Requests\ReferrerRequest;
use App\Domain\Members\Http\Resources\ReferrerResource;
use App\Domain\Members\Models\BusinessReferrer;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/referrers : apporteurs d'affaires du centre (onglet « Apporteurs d'affaires » de
 * /cga/parametres, RG-PTF-06) — liste, création, modification, suppression d'un apporteur jamais
 * rattaché.
 */
class ReferrerController extends BaseApiController
{
    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('viewAny', BusinessReferrer::class);

            return ReferrerResource::collection(BusinessReferrer::query()->withCount('members')->orderByDesc('active')->orderBy('label')->get());
        }, action: 'referrers.index');
    }

    public function store(ReferrerRequest $request, SaveReferrer $save): JsonResponse
    {
        return $this->handle(function () use ($request, $save): JsonResponse {
            $referrer = $save->execute(null, $request->fields());
            $this->logSubject($referrer);

            return (new ReferrerResource($referrer->loadCount('members')))->response()->setStatusCode(201);
        }, action: 'referrers.store');
    }

    public function update(ReferrerRequest $request, BusinessReferrer $referrer, SaveReferrer $save): ReferrerResource|JsonResponse
    {
        return $this->handle(function () use ($request, $referrer, $save): ReferrerResource {
            $referrer = $save->execute($referrer, $request->fields());
            $this->logSubject($referrer);

            return new ReferrerResource($referrer->loadCount('members'));
        }, action: 'referrers.update');
    }

    public function destroy(BusinessReferrer $referrer, DeleteReferrer $delete): Response|JsonResponse
    {
        return $this->handle(function () use ($referrer, $delete): Response {
            Gate::authorize('delete', $referrer);
            $delete->execute($referrer);

            return response()->noContent();
        }, action: 'referrers.destroy');
    }
}
