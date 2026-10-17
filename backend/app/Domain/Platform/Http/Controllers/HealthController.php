<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Platform\Http\Resources\HealthReportResource;
use App\Http\Controllers\Controller;
use App\Support\Health\HealthMonitor;
use App\Support\Health\HealthStatus;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/health : 200 si la base, les files, le disque et LibreOffice sont bons, 503 sinon.
 */
class HealthController extends Controller
{
    public function __invoke(HealthMonitor $monitor): JsonResponse
    {
        $report = $monitor->report();

        return HealthReportResource::make($report)
            ->response()
            ->setStatusCode($report->status() === HealthStatus::Ok ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
