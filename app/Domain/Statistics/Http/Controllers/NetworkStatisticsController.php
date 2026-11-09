<?php

namespace App\Domain\Statistics\Http\Controllers;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Statistics\Actions\ProduceStatisticsBrochure;
use App\Domain\Statistics\Actions\RequestStatisticsRefresh;
use App\Domain\Statistics\Dashboards\NetworkDashboard;
use App\Domain\Statistics\Http\Requests\ListBrochuresRequest;
use App\Domain\Statistics\Http\Requests\NationalStatisticsRequest;
use App\Domain\Statistics\Http\Requests\NetworkDashboardRequest;
use App\Domain\Statistics\Http\Requests\ProduceBrochureRequest;
use App\Domain\Statistics\Http\Requests\RefreshStatisticsRequest;
use App\Domain\Statistics\Http\Resources\BrochureResource;
use App\Domain\Statistics\Http\Resources\DirectorateCommunesResource;
use App\Domain\Statistics\Http\Resources\NationalStatisticsResource;
use App\Domain\Statistics\Http\Resources\NetworkDashboardResource;
use App\Domain\Statistics\Http\Resources\StatisticsMapResource;
use App\Domain\Statistics\Support\NationalStatistics;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Espace CNCGA (PRD M15) : tableau de bord (GET /network/dashboard, F-DSH-01), statistiques nationales
 * (GET /network/statistics, F-STA-01), carte par Direction régionale et détail par commune, bouton « Actualiser »
 * (POST /network/statistics/refresh, 202), plaquette PDF annuelle (POST /network/statistics/brochures, 202, F-STA-03) et
 * plaquettes produites (GET /network/statistics/brochures).
 */
class NetworkStatisticsController extends BaseApiController
{
    public function dashboard(NetworkDashboardRequest $request, NetworkDashboard $dashboard): NetworkDashboardResource|JsonResponse
    {
        return $this->handle(fn (): NetworkDashboardResource => new NetworkDashboardResource($dashboard->build($request->period(), CarbonImmutable::today())), action: 'network.dashboard');
    }

    public function statistics(NationalStatisticsRequest $request, NationalStatistics $statistics): NationalStatisticsResource|JsonResponse
    {
        return $this->handle(fn (): NationalStatisticsResource => new NationalStatisticsResource($statistics->build($request->period(), CarbonImmutable::today())), action: 'network.statistics');
    }

    public function map(NationalStatisticsRequest $request, NationalStatistics $statistics): StatisticsMapResource|JsonResponse
    {
        return $this->handle(fn (): StatisticsMapResource => new StatisticsMapResource($statistics->map($request->period(), CarbonImmutable::today())), action: 'network.statistics.map');
    }

    public function communes(NationalStatisticsRequest $request, RegionalDirectorate $regionalDirectorate, NationalStatistics $statistics): DirectorateCommunesResource|JsonResponse
    {
        return $this->handle(fn (): DirectorateCommunesResource => new DirectorateCommunesResource($statistics->communes($regionalDirectorate, $request->period(), CarbonImmutable::today())), action: 'network.statistics.communes');
    }

    public function brochures(ListBrochuresRequest $request): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(fn (): AnonymousResourceCollection => BrochureResource::collection(GeneratedDocument::query()
            ->with('pdf')
            ->where('type', DocumentType::StatisticsBrochure)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit((int) config('cga.exports.history_items'))
            ->get()), action: 'network.statistics.brochures');
    }

    public function produceBrochure(ProduceBrochureRequest $request, ProduceStatisticsBrochure $produce, TenantContext $context): JsonResponse
    {
        return $this->handle(function () use ($request, $produce, $context): JsonResponse {
            $document = $produce->execute($request->year(), $context->membership());
            $this->logSubject($document);

            return (new BrochureResource($document))->response()->setStatusCode(202);
        }, action: 'network.statistics.brochure');
    }

    public function refresh(RefreshStatisticsRequest $request, RequestStatisticsRefresh $refresh, TenantContext $context): JsonResponse
    {
        return $this->handle(fn (): JsonResponse => response()->json(['data' => $refresh->execute($context->organization())], 202), action: 'network.statistics.refresh');
    }
}
