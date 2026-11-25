<?php

namespace App\Domain\Imports\Http\Controllers;

use App\Domain\Imports\Actions\PreviewMemberImport;
use App\Domain\Imports\Actions\StartImport;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Http\Requests\MemberImportRequest;
use App\Domain\Imports\Http\Requests\StartCgaImportRequest;
use App\Domain\Imports\Http\Resources\ImportBatchResource;
use App\Domain\Imports\Importers\MemberRowImporter;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Domain\Imports\Support\MemberImportStart;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * /api/v1/cga/imports/members : import d'un portefeuille existant par le gérant (F-IMP-02) — modèle
 * Excel, analyse et aperçu (ancien n° → n° CGA Connect), dépôt traité en file « imports », puis
 * avancement et rapport par les routes communes des imports.
 */
class MemberImportController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function template(MemberRowImporter $importer, ImportWorkbook $workbook): Response|JsonResponse
    {
        return $this->handle(function () use ($importer, $workbook): Response {
            Gate::authorize('create', [ImportBatch::class, ImportType::Members]);

            return response($workbook->template($importer), 200, [
                'Content-Type' => StartCgaImportRequest::XLSX,
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'modele-'.$importer->fileName().'.xlsx'),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }, action: 'imports.members.template');
    }

    public function preview(MemberImportRequest $request, PreviewMemberImport $preview): JsonResponse
    {
        return $this->handle(function () use ($request, $preview): JsonResponse {
            return response()->json(['data' => $preview->execute($this->context->organizationId(), $request->spreadsheet(), $request->startNumber())]);
        }, action: 'imports.members.preview');
    }

    public function store(MemberImportRequest $request, MemberImportStart $start, StartImport $import): JsonResponse
    {
        return $this->handle(function () use ($request, $start, $import): JsonResponse {
            ['start' => $number] = $start->check($this->context->organizationId(), $request->startNumber());
            $batch = $import->execute(ImportType::Members, $request->spreadsheet(), $this->context->membership(), ['start_number' => $number]);
            $this->logSubject($batch);

            return (new ImportBatchResource($batch->load(['file', 'report', 'creator'])))->response()->setStatusCode(202);
        }, action: 'imports.members.store');
    }
}
