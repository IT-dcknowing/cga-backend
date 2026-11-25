<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Platform\Http\Resources\HealthReportResource;
use App\Support\Health\HealthMonitor;
use App\Support\Health\HealthStatus;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/health : 200 si la base, les files, le disque et LibreOffice sont bons, 503 sinon.
 */
class HealthController extends BaseApiController
{
    public function __invoke(HealthMonitor $monitor): JsonResponse
    {
        return $this->handle(function () use ($monitor): JsonResponse {
            $report = $monitor->report();

            return HealthReportResource::make($report)
                ->response()
                ->setStatusCode($report->status() === HealthStatus::Ok ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
        }, action: 'platform.health');
    }
}
