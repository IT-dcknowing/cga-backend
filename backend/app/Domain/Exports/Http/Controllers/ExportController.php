<?php

namespace App\Domain\Exports\Http\Controllers;

use App\Domain\Exports\Actions\RequestExport;
use App\Domain\Exports\Http\Requests\ListExportsRequest;
use App\Domain\Exports\Http\Requests\ShowExportRequest;
use App\Domain\Exports\Http\Requests\StoreExportRequest;
use App\Domain\Exports\Http\Resources\ExportResource;
use App\Domain\Exports\Models\Export;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Exports Excel en tâche de fond (F-EXP-01, RG-SEC-06), dans l'espace actif (/network/exports pour le CNCGA,
 * /cga/exports pour un CGA) : demande (202, fichier produit en file), derniers exports de la personne, suivi et
 * téléchargement d'un export.
 */
class ExportController extends BaseApiController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(ListExportsRequest $request): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(fn (): AnonymousResourceCollection => ExportResource::collection(Export::query()
            ->with('file')
            ->where('membership_id', $this->context->membership()->id)
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit((int) config('cga.exports.history_items'))
            ->get()), action: 'exports.index');
    }

    public function store(StoreExportRequest $request, RequestExport $export): JsonResponse
    {
        return $this->handle(function () use ($request, $export): JsonResponse {
            $created = $export->execute($request->exportType(), $request->period(), $request->filters(), $this->context->membership());
            $this->logSubject($created);

            return (new ExportResource($created))->response()->setStatusCode(202);
        }, action: 'exports.store');
    }

    public function show(ShowExportRequest $request, Export $export): ExportResource|JsonResponse
    {
        return $this->handle(function () use ($export): ExportResource {
            $this->logSubject($export);

            return new ExportResource($export->load('file'));
        }, action: 'exports.show');
    }
}
