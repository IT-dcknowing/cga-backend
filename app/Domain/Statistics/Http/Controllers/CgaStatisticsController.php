<?php

namespace App\Domain\Statistics\Http\Controllers;

use App\Domain\Statistics\Actions\RequestStatisticsRefresh;
use App\Domain\Statistics\Dashboards\CgaDashboard;
use App\Domain\Statistics\Http\Requests\CgaDashboardRequest;
use App\Domain\Statistics\Http\Requests\CgaPositionRequest;
use App\Domain\Statistics\Http\Requests\RefreshStatisticsRequest;
use App\Domain\Statistics\Http\Resources\CgaDashboardResource;
use App\Domain\Statistics\Http\Resources\CgaPositionResource;
use App\Domain\Statistics\Support\CgaPosition;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Espace CGA (PRD M15) : tableau de bord (GET /cga/dashboard, F-DSH-02), position dans le réseau
 * (GET /cga/statistics, F-STA-02), bouton « Actualiser » (POST /cga/statistics/refresh, 202).
 */
class CgaStatisticsController extends BaseApiController
{
    public function dashboard(CgaDashboardRequest $request, CgaDashboard $dashboard): CgaDashboardResource|JsonResponse
    {
        return $this->handle(fn (): CgaDashboardResource => new CgaDashboardResource($dashboard->build($request->period(), CarbonImmutable::today())), action: 'cga.dashboard');
    }

    public function position(CgaPositionRequest $request, CgaPosition $position): CgaPositionResource|JsonResponse
    {
        return $this->handle(fn (): CgaPositionResource => new CgaPositionResource($position->build($request->period(), CarbonImmutable::today())), action: 'cga.statistics');
    }

    public function refresh(RefreshStatisticsRequest $request, RequestStatisticsRefresh $refresh, TenantContext $context): JsonResponse
    {
        return $this->handle(fn (): JsonResponse => response()->json(['data' => $refresh->execute($context->organization())], 202), action: 'cga.statistics.refresh');
    }
}
