<?php

namespace App\Domain\Imports\Http\Controllers;

use App\Domain\Imports\Actions\StartImport;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Http\Requests\ListImportRowsRequest;
use App\Domain\Imports\Http\Requests\ListImportsRequest;
use App\Domain\Imports\Http\Requests\StartCgaImportRequest;
use App\Domain\Imports\Http\Resources\ImportBatchResource;
use App\Domain\Imports\Http\Resources\ImportRowResource;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\ImporterRegistry;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Support\Config\SettingsService;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * /api/v1/network/imports : import du registre des CGA par le CNCGA (F-IMP-01) — modèle à
 * télécharger, dépôt du fichier (traité en file d'attente : 202), suivi de l'avancement, rapport
 * d'anomalies ligne par ligne.
 */
class ImportController extends BaseApiController
{
    public function index(ListImportsRequest $request, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $settings): AnonymousResourceCollection {
            $batches = QueryBuilder::for(ImportBatch::query()->with(['file', 'report', 'creator'])->where('type', $request->importType()), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('-created_at')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return ImportBatchResource::collection($batches);
        }, action: 'imports.index');
    }

    public function show(ImportBatch $importBatch): ImportBatchResource|JsonResponse
    {
        return $this->handle(function () use ($importBatch): ImportBatchResource {
            Gate::authorize('view', $importBatch);

            return new ImportBatchResource($importBatch->load(['file', 'report', 'creator']));
        }, action: 'imports.show');
    }

    public function rows(ListImportRowsRequest $request, ImportBatch $importBatch, SettingsService $settings): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($request, $importBatch, $settings): AnonymousResourceCollection {
            $rows = QueryBuilder::for($importBatch->rows(), $request->listQuery())
                ->allowedFilters(...$request->queryFilters())
                ->allowedSorts(...$request->sorts())
                ->defaultSort('row_number')
                ->paginate($request->perPage($settings), ['*'], 'page', $request->page());

            return ImportRowResource::collection($rows);
        }, action: 'imports.rows');
    }

    public function cgaTemplate(ImporterRegistry $importers, ImportWorkbook $workbook): Response|JsonResponse
    {
        return $this->handle(function () use ($importers, $workbook): Response {
            Gate::authorize('create', [ImportBatch::class, ImportType::Cgas]);
            $importer = $importers->for(ImportType::Cgas);

            return response($workbook->template($importer), 200, [
                'Content-Type' => StartCgaImportRequest::XLSX,
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'modele-'.$importer->fileName().'.xlsx'),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }, action: 'imports.cgas.template');
    }

    public function storeCgas(StartCgaImportRequest $request, TenantContext $context, StartImport $start): JsonResponse
    {
        return $this->handle(function () use ($request, $context, $start): JsonResponse {
            $batch = $start->execute(ImportType::Cgas, $request->spreadsheet(), $context->membership());
            $this->logSubject($batch);

            return (new ImportBatchResource($batch->load(['file', 'report', 'creator'])))->response()->setStatusCode(202);
        }, action: 'imports.cgas.store');
    }
}
