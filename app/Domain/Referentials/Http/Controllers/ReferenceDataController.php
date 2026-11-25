<?php

namespace App\Domain\Referentials\Http\Controllers;

use App\Domain\Imports\Http\Requests\StartCgaImportRequest;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Domain\Referentials\Actions\ImportReferenceData;
use App\Domain\Referentials\Actions\ValidateReferenceData;
use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Referentials\Http\Requests\ReferenceImportRequest;
use App\Domain\Referentials\ReferenceData\ReferenceDataset;
use App\Domain\Referentials\ReferenceData\ReferenceImportPlanner;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * /api/v1/reference-data : données de référence remplaçables sans développement (RG-DATA-01, 02,
 * onglet « Données de référence » de /plateforme) — nombre d'éléments et de provisoires par jeu,
 * modèle Excel, aperçu puis application d'un fichier (« mettre à jour » ou « remplacer »),
 * « Marquer comme validé ».
 */
class ReferenceDataController extends BaseApiController
{
    public function __construct(private readonly ReferenceImportPlanner $planner) {}

    public function index(): JsonResponse
    {
        return $this->handle(function (): JsonResponse {
            Gate::authorize('viewAny', ReferenceDataset::class);

            $datasets = array_map(function (ReferenceDataset $dataset): array {
                $query = $this->planner->definition($dataset)->query();
                $count = (clone $query)->count();
                $provisional = (clone $query)->where('data_status', DataStatus::Provisional)->count();

                return [
                    'dataset' => $dataset->value,
                    'label' => $dataset->label(),
                    'count' => $count,
                    'provisional_count' => $provisional,
                    'status' => $provisional > 0 ? DataStatus::Provisional->value : DataStatus::Validated->value,
                    'can' => ['manage' => Gate::allows('manage', $dataset)],
                ];
            }, ReferenceDataset::cases());

            return response()->json(['data' => $datasets, 'meta' => ['provisional_count' => array_sum(array_column($datasets, 'provisional_count'))]]);
        }, action: 'reference-data.index');
    }

    public function template(ReferenceDataset $dataset, ImportWorkbook $workbook): Response|JsonResponse
    {
        return $this->handle(function () use ($dataset, $workbook): Response {
            Gate::authorize('manage', $dataset);
            $definition = $this->planner->definition($dataset);

            return response($workbook->template($definition), 200, [
                'Content-Type' => StartCgaImportRequest::XLSX,
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'modele-'.$definition->fileName().'.xlsx'),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }, action: 'reference-data.template');
    }

    public function preview(ReferenceImportRequest $request, ReferenceDataset $dataset, TenantContext $context, ImportReferenceData $import): JsonResponse
    {
        return $this->handle(function () use ($request, $dataset, $context, $import): JsonResponse {
            [$file, $preview] = $import->preview($dataset, $request->spreadsheet(), $request->mode(), $context->organizationId(), $this->currentUser());

            return response()->json(['data' => ['file_id' => $file->id, ...$preview]]);
        }, action: 'reference-data.preview');
    }

    public function apply(ReferenceImportRequest $request, ReferenceDataset $dataset, TenantContext $context, ImportReferenceData $import): JsonResponse
    {
        return $this->handle(function () use ($request, $dataset, $context, $import): JsonResponse {
            return response()->json(['data' => $import->apply($dataset, $request->storedFile($context), $request->mode())]);
        }, action: 'reference-data.apply');
    }

    public function validate(ReferenceDataset $dataset, ValidateReferenceData $validate): JsonResponse
    {
        return $this->handle(function () use ($dataset, $validate): JsonResponse {
            Gate::authorize('manage', $dataset);

            return response()->json(['data' => ['dataset' => $dataset->value, 'validated' => $validate->execute($dataset)]]);
        }, action: 'reference-data.validate');
    }
}
