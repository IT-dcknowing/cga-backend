<?php

namespace App\Domain\Obligations\Http\Controllers;

use App\Domain\Obligations\Actions\SaveCenterObligation;
use App\Domain\Obligations\Http\Requests\CenterObligationRequest;
use App\Domain\Obligations\Http\Resources\CenterObligationResource;
use App\Domain\Obligations\Models\CenterObligation;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/center-obligations : obligations propres du CGA (/cga/gestion-interne, onglet
 * « Obligations du centre », F-OBL-07) — liste par échéance, ajout, modification (dont le statut).
 */
class CenterObligationController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('manage', CenterObligation::class);

            return CenterObligationResource::collection(CenterObligation::query()->orderBy('due_date')->orderBy('label')->get());
        }, action: 'center_obligations.index');
    }

    public function store(CenterObligationRequest $request, SaveCenterObligation $save): JsonResponse
    {
        return $this->handle(function () use ($request, $save): JsonResponse {
            $obligation = $save->execute($this->context->organizationId(), $request->entry());
            $this->logSubject($obligation);

            return (new CenterObligationResource($obligation))->response()->setStatusCode(201);
        }, action: 'center_obligations.store');
    }

    public function update(CenterObligationRequest $request, CenterObligation $centerObligation, SaveCenterObligation $save): CenterObligationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $centerObligation, $save): CenterObligationResource {
            $obligation = $save->execute($this->context->organizationId(), $request->entry(), $centerObligation);
            $this->logSubject($obligation);

            return new CenterObligationResource($obligation);
        }, action: 'center_obligations.update');
    }
}
