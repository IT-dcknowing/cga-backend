<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Http\Requests\NetworkReportsRequest;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Support\NetworkReportStatus;
use App\Domain\Reports\Support\ReportDeadlines;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /api/v1/cncga/quarterly-reports : rapports trimestriels vus du réseau (/cncga/rapports, RG-RPT-04) —
 * pour un trimestre, l'état de transmission de chaque CGA et, une fois transmis, le seul tableau de
 * synthèse agrégé (feuille Résumé). Aucune donnée nominative d'adhérent : les rapports des centres sont
 * lus par un contournement journalisé, et seules ces colonnes en sortent.
 */
class NetworkReportController extends BaseApiController
{
    public function index(NetworkReportsRequest $request, ReportDeadlines $deadlines): JsonResponse
    {
        return $this->handle(function () use ($request, $deadlines): JsonResponse {
            $deadline = $deadlines->deadline($request->year(), $request->quarter());
            $legal = $deadlines->legalDeadline($deadline);
            $today = CarbonImmutable::today();
            /** @var Collection<string, QuarterlyReport> $reports */
            $reports = Tenancy::bypass(static fn (): Collection => QuarterlyReport::query()
                ->where('year', $request->year())
                ->where('quarter', $request->quarter())
                ->get(['id', 'organization_id', 'status', 'current_version', 'transmitted_at', 'acknowledged_at'])
                ->keyBy('organization_id'), 'Rapports trimestriels : état de transmission de chaque CGA pour le CNCGA (RG-RPT-04).');

            $rows = Organization::query()
                ->where('type', OrganizationType::Cga)
                ->where('status', '<>', OrganizationStatus::Terminated)
                ->orderBy('name')
                ->get(['id', 'name', 'sigle'])
                ->map(static function (Organization $cga) use ($reports, $deadline, $legal, $today): array {
                    $report = $reports->get($cga->id);
                    $status = NetworkReportStatus::of($report, $deadline, $legal, $today);

                    return [
                        'cga' => ['id' => $cga->id, 'name' => $cga->name, 'sigle' => $cga->sigle],
                        'status' => $status,
                        'status_label' => __('report.network_status.'.$status),
                        'version' => $report?->transmitted_at === null ? null : $report->current_version,
                        'transmitted_at' => $report?->transmitted_at?->toIso8601ZuluString(),
                        'acknowledged_at' => $report?->acknowledged_at?->toDateString(),
                    ];
                })
                ->values();

            return response()->json([
                'data' => $rows,
                'meta' => [
                    'year' => $request->year(),
                    'quarter' => $request->quarter(),
                    'deadline' => $deadline->toDateString(),
                    'legal_deadline' => $legal->toDateString(),
                    'counts' => $rows->countBy('status')->all(),
                ],
            ]);
        }, action: 'network_reports.index');
    }

    public function summary(NetworkReportsRequest $request, Organization $organization): JsonResponse
    {
        return $this->handle(function () use ($request, $organization): JsonResponse {
            if ($organization->type !== OrganizationType::Cga) {
                throw new NotFoundHttpException;
            }
            $report = Tenancy::bypass(static fn (): ?QuarterlyReport => QuarterlyReport::query()
                ->where('organization_id', $organization->id)
                ->where('year', $request->year())
                ->where('quarter', $request->quarter())
                ->whereNotNull('transmitted_at')
                ->first(['id', 'organization_id', 'year', 'quarter', 'status', 'transmitted_at', 'summary']), 'Rapport trimestriel : tableau de synthèse agrégé transmis, pour le CNCGA (RG-RPT-04).');
            if ($report === null || $report->summary === null) {
                throw new NotFoundHttpException;
            }
            $this->logSubject($report);

            return response()->json(['data' => [
                'cga' => ['id' => $organization->id, 'name' => $organization->name],
                'year' => $report->year,
                'quarter' => $report->quarter,
                'transmitted_at' => $report->transmitted_at?->toIso8601ZuluString(),
                'summary' => $report->summary,
            ]]);
        }, action: 'network_reports.summary');
    }
}
