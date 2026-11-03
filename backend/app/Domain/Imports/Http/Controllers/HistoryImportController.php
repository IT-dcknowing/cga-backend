<?php

namespace App\Domain\Imports\Http\Controllers;

use App\Domain\Imports\Actions\StartImport;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Http\Requests\StartCgaImportRequest;
use App\Domain\Imports\Http\Requests\StartHistoryImportRequest;
use App\Domain\Imports\Http\Resources\ImportBatchResource;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\ImporterRegistry;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * /api/v1/cga/imports/revenues et /tax-payments : reprise d'historique par les agents du centre
 * (F-IMP-03) — chiffres d'affaires par année, paiements d'impôts par nature et par période. Modèle Excel,
 * dépôt traité en file « imports » (202), puis avancement et rapport par les routes communes des imports.
 */
class HistoryImportController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function revenuesTemplate(ImporterRegistry $importers, ImportWorkbook $workbook): Response|JsonResponse
    {
        return $this->handle(fn (): Response => $this->template(ImportType::Revenues, $importers, $workbook), action: 'imports.revenues.template');
    }

    public function taxPaymentsTemplate(ImporterRegistry $importers, ImportWorkbook $workbook): Response|JsonResponse
    {
        return $this->handle(fn (): Response => $this->template(ImportType::TaxPayments, $importers, $workbook), action: 'imports.tax_payments.template');
    }

    public function store(StartHistoryImportRequest $request, StartImport $start): JsonResponse
    {
        return $this->handle(function () use ($request, $start): JsonResponse {
            $batch = $start->execute($request->importType(), $request->spreadsheet(), $this->context->membership());
            $this->logSubject($batch);

            return (new ImportBatchResource($batch->load(['file', 'report', 'creator'])))->response()->setStatusCode(202);
        }, action: 'imports.history.store');
    }

    private function template(ImportType $type, ImporterRegistry $importers, ImportWorkbook $workbook): Response
    {
        Gate::authorize('create', [ImportBatch::class, $type]);
        $importer = $importers->for($type);

        return response($workbook->template($importer), 200, [
            'Content-Type' => StartCgaImportRequest::XLSX,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'modele-'.$importer->fileName().'.xlsx'),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
