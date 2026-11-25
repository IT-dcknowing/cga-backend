<?php

namespace App\Domain\Obligations\Http\Controllers;

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Obligations\Actions\SaveLocalObligationType;
use App\Domain\Obligations\Http\Requests\LocalObligationTypeRequest;
use App\Domain\Obligations\Http\Requests\ObligationSuggestionsRequest;
use App\Domain\Obligations\Http\Resources\ObligationTypeResource;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Obligations\Support\ApplicabilityEngine;
use App\Domain\Referentials\Models\Sector;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/obligation-types : catalogue vu du centre (F-OBL-01) — obligations nationales en vigueur
 * et obligations locales du centre (RG-OBL-01) —, obligations applicables à un futur dossier
 * (RG-OBL-02, 03), création et modification des obligations locales.
 */
class ObligationTypeController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('viewAny', ObligationType::class);
            $organizationId = $this->context->organizationId();

            return ObligationTypeResource::collection(ObligationType::query()
                ->where(static function (Builder $visible) use ($organizationId): void {
                    $visible->where(static fn (Builder $national) => $national->whereNull('organization_id')->where('active', true))
                        ->orWhere('organization_id', $organizationId);
                })
                ->orderBy('category')
                ->orderBy('label')
                ->get());
        }, action: 'obligation_types.index');
    }

    public function suggestions(ObligationSuggestionsRequest $request, ApplicabilityEngine $engine): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $engine): AnonymousResourceCollection {
            $sectorId = $request->validated('sector_id');
            $sector = $sectorId === null ? null : Sector::query()->visibleIn($this->context)->find($sectorId);
            $employees = $request->validated('employees_count');

            return ObligationTypeResource::collection($engine->filter(
                ObligationType::query()->inForceFor($this->context->organizationId())->orderBy('category')->orderBy('label')->get(),
                TaxRegime::from((string) $request->validated('regime')),
                $employees === null ? null : (int) $employees,
                $sector?->code,
            ));
        }, action: 'obligation_types.suggestions');
    }

    public function store(LocalObligationTypeRequest $request, SaveLocalObligationType $save): JsonResponse
    {
        return $this->handle(function () use ($request, $save): JsonResponse {
            $type = $save->execute(null, $this->context->organizationId(), $request->validated());
            $this->logSubject($type);

            return (new ObligationTypeResource($type))->response()->setStatusCode(201);
        }, action: 'obligation_types.store');
    }

    public function update(LocalObligationTypeRequest $request, ObligationType $obligationType, SaveLocalObligationType $save): ObligationTypeResource|JsonResponse
    {
        return $this->handle(function () use ($request, $obligationType, $save): ObligationTypeResource {
            $type = $save->execute($obligationType, $this->context->organizationId(), $request->validated());
            $this->logSubject($type);

            return new ObligationTypeResource($type);
        }, action: 'obligation_types.update');
    }
}
