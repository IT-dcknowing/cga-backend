<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Platform\Actions\RetryFailedJob;
use App\Domain\Platform\Http\Requests\ListFailedJobsRequest;
use App\Domain\Platform\Http\Resources\FailedJobResource;
use App\Domain\Platform\Models\FailedJob;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Queue\QueueMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Écran « Technique » de /plateforme (02-TRD §12.3, sans Horizon) : état de chaque file surveillée (travaux en
 * attente, retard du plus ancien, travaux en échec), liste des travaux en échec et relance.
 */
class QueueController extends BaseApiController
{
    /**
     * GET /api/v1/platform/queues
     */
    public function index(QueueMonitor $monitor): JsonResponse
    {
        return $this->handle(function () use ($monitor): JsonResponse {
            Gate::authorize('manage', FailedJob::class);

            $failed = FailedJob::query()->selectRaw('queue, count(*) as total')->groupBy('queue')->pluck('total', 'queue');

            return response()->json([
                'data' => array_map(static fn (array $state): array => [...$state, 'failed' => (int) ($failed[$state['queue']] ?? 0)], $monitor->snapshot()),
                'meta' => ['threshold_minutes' => $monitor->thresholdMinutes()],
            ]);
        }, action: 'platform.queues.index');
    }

    /**
     * GET /api/v1/platform/failed-jobs
     */
    public function failed(ListFailedJobsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(fn (): AnonymousResourceCollection => FailedJobResource::collection(
            QueryBuilder::for(FailedJob::query(), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-failed_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page()),
        ), action: 'platform.failed_jobs.index');
    }

    /**
     * POST /api/v1/platform/failed-jobs/{failedJob}/retry
     */
    public function retry(FailedJob $failedJob, RetryFailedJob $retry): JsonResponse
    {
        return $this->handle(function () use ($failedJob, $retry): JsonResponse {
            Gate::authorize('manage', FailedJob::class);
            $retry->execute($failedJob);

            return response()->json(null, 204);
        }, action: 'platform.failed_jobs.retry');
    }
}
