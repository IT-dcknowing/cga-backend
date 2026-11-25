<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Reports\Actions\AcknowledgeQuarterlyReport;
use App\Domain\Reports\Actions\CorrectQuarterlyReport;
use App\Domain\Reports\Actions\CreateQuarterlyReport;
use App\Domain\Reports\Actions\GenerateQuarterlyReport;
use App\Domain\Reports\Actions\SaveQuarterlyReport;
use App\Domain\Reports\Actions\TransmitQuarterlyReport;
use App\Domain\Reports\Http\Requests\AcknowledgeReportRequest;
use App\Domain\Reports\Http\Requests\CorrectReportRequest;
use App\Domain\Reports\Http\Requests\CreateReportRequest;
use App\Domain\Reports\Http\Requests\SaveReportRequest;
use App\Domain\Reports\Http\Requests\TransmitReportRequest;
use App\Domain\Reports\Http\Resources\QuarterlyReportResource;
use App\Domain\Reports\Http\Resources\QuarterlyReportVersionResource;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Support\ClosingProposals;
use App\Domain\Reports\Support\PortfolioStates;
use App\Domain\Reports\Support\QuarterlyReportCalculator;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/quarterly-reports : rapports trimestriels du centre (/cga/rapports, F-RPT-02) — liste,
 * « Préparer le rapport T{n} », détail avec complétude par section (RG-RPT-02), enregistrement
 * automatique, états du portefeuille calculés en lecture (section 5, RG-RPT-01, portefeuille CGA seul).
 */
class QuarterlyReportController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        return $this->handle(function (): JsonResponse {
            Gate::authorize('view', QuarterlyReport::class);
            $reports = QuarterlyReport::query()->orderByDesc('year')->orderByDesc('quarter')->get();
            $existing = $reports->map(static fn (QuarterlyReport $report): string => "{$report->year}-{$report->quarter}")->all();

            return QuarterlyReportResource::collection($reports)->additional(['meta' => [
                // Trimestres que l'on peut encore préparer (« + Préparer le rapport T{n} »).
                'creatable' => array_values(array_filter(
                    CreateQuarterlyReport::creatable(CarbonImmutable::today()),
                    static fn (array $period): bool => ! in_array("{$period['year']}-{$period['quarter']}", $existing, true),
                )),
                'can' => ['prepare' => Gate::allows('prepare', QuarterlyReport::class)],
            ]])->response();
        }, action: 'quarterly_reports.index');
    }

    public function store(CreateReportRequest $request, CreateQuarterlyReport $create): JsonResponse
    {
        return $this->handle(function () use ($request, $create): JsonResponse {
            $report = $create->execute($this->context->organization()->loadMissing('regionalDirectorate'), $request->year(), $request->quarter());
            $this->logSubject($report);

            return (new QuarterlyReportResource($report))->detailed()->response()->setStatusCode(201);
        }, action: 'quarterly_reports.store');
    }

    public function show(QuarterlyReport $quarterlyReport, ClosingProposals $proposals): QuarterlyReportResource|JsonResponse
    {
        return $this->handle(function () use ($quarterlyReport, $proposals): QuarterlyReportResource {
            Gate::authorize('view', QuarterlyReport::class);

            return (new QuarterlyReportResource($quarterlyReport->load(['transmitter', 'acknowledgementFile'])))
                ->detailed($proposals->forReport($quarterlyReport->organization_id, $quarterlyReport->year));
        }, action: 'quarterly_reports.show');
    }

    public function update(SaveReportRequest $request, QuarterlyReport $quarterlyReport, SaveQuarterlyReport $save): QuarterlyReportResource|JsonResponse
    {
        return $this->handle(fn (): QuarterlyReportResource => (new QuarterlyReportResource(
            $save->execute($quarterlyReport, $request->changes())->load(['transmitter', 'acknowledgementFile']),
        ))->detailed(), action: 'quarterly_reports.update');
    }

    public function states(QuarterlyReport $quarterlyReport, PortfolioStates $states, SettingsService $settings): JsonResponse
    {
        return $this->handle(function () use ($quarterlyReport, $states, $settings): JsonResponse {
            Gate::authorize('view', QuarterlyReport::class);
            /** @var array{basis: string, min_increase_pct: int|float, amount: string} $rule */
            $rule = (array) $settings->get('report.increase_rule');

            return response()->json(['data' => $states->compute($quarterlyReport->organization_id, $quarterlyReport->year, $quarterlyReport->quarter, $rule)]);
        }, action: 'quarterly_reports.states');
    }

    /**
     * « Prévisualiser » : l'objet de données tel qu'il serait généré maintenant (rien n'est enregistré).
     */
    public function preview(QuarterlyReport $quarterlyReport, QuarterlyReportCalculator $calculator): JsonResponse
    {
        return $this->handle(function () use ($quarterlyReport, $calculator): JsonResponse {
            Gate::authorize('view', QuarterlyReport::class);

            return response()->json(['data' => $calculator->build($quarterlyReport, CarbonImmutable::now())]);
        }, action: 'quarterly_reports.preview');
    }

    public function generate(QuarterlyReport $quarterlyReport, GenerateQuarterlyReport $generate): JsonResponse
    {
        return $this->handle(function () use ($quarterlyReport, $generate): JsonResponse {
            Gate::authorize('prepare', QuarterlyReport::class);
            $report = $generate->execute($quarterlyReport, $this->context->membership(), CarbonImmutable::now());

            return (new QuarterlyReportResource($report->load(['transmitter', 'acknowledgementFile'])))->detailed()->response()->setStatusCode(202);
        }, action: 'quarterly_reports.generate');
    }

    public function versions(QuarterlyReport $quarterlyReport): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($quarterlyReport): AnonymousResourceCollection {
            Gate::authorize('view', QuarterlyReport::class);

            return QuarterlyReportVersionResource::collection($quarterlyReport->versions()
                ->with(['generator', 'pdfDocument.pdf', 'pdfDocument.docx', 'xlsxDocument.xlsx', 'slipDocument.pdf'])
                ->orderByDesc('version')
                ->get());
        }, action: 'quarterly_reports.versions');
    }

    public function transmit(TransmitReportRequest $request, QuarterlyReport $quarterlyReport, TransmitQuarterlyReport $transmit): QuarterlyReportResource|JsonResponse
    {
        return $this->handle(fn (): QuarterlyReportResource => $this->detail($transmit->execute(
            $quarterlyReport, $request->transmittedOn(), $request->mode(), $request->reference(), $this->context->membership(), CarbonImmutable::now(),
        )), action: 'quarterly_reports.transmit');
    }

    public function acknowledge(AcknowledgeReportRequest $request, QuarterlyReport $quarterlyReport, AcknowledgeQuarterlyReport $acknowledge): QuarterlyReportResource|JsonResponse
    {
        return $this->handle(fn (): QuarterlyReportResource => $this->detail($acknowledge->execute(
            $quarterlyReport, $request->acknowledgedOn(), $request->reference(), $request->scan(), $this->currentUser(),
        )), action: 'quarterly_reports.acknowledge');
    }

    public function correct(CorrectReportRequest $request, QuarterlyReport $quarterlyReport, CorrectQuarterlyReport $correct): QuarterlyReportResource|JsonResponse
    {
        return $this->handle(fn (): QuarterlyReportResource => $this->detail($correct->execute($quarterlyReport, $request->reason())), action: 'quarterly_reports.correct');
    }

    private function detail(QuarterlyReport $report): QuarterlyReportResource
    {
        return (new QuarterlyReportResource($report->load(['transmitter', 'acknowledgementFile'])))->detailed();
    }
}
