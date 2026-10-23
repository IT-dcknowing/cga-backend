<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Actions\CancelDocument;
use App\Domain\Documents\Actions\RegenerateDocument;
use App\Domain\Documents\Actions\RetryDocument;
use App\Domain\Documents\Http\Requests\CancelDocumentRequest;
use App\Domain\Documents\Http\Resources\DocumentResource;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/documents/{document} : un document du centre — état de sa production (ligne « en
 * production » remplacée quand il est prêt, RG-DOC-05), liens signés du PDF et du Word, relance d'une
 * production en échec, régénération ou duplicata (RG-ADH-07, RG-DOC-06), annulation (F-DOC-03).
 */
class DocumentController extends BaseApiController
{
    public function show(GeneratedDocument $document): DocumentResource|JsonResponse
    {
        return $this->handle(function () use ($document): DocumentResource {
            Gate::authorize('view', $document);

            return new DocumentResource($document->load(['member', 'pdf', 'docx']));
        }, action: 'documents.show');
    }

    public function retry(GeneratedDocument $document, TenantContext $context, RetryDocument $retry): JsonResponse
    {
        return $this->handle(function () use ($document, $context, $retry): JsonResponse {
            Gate::authorize('retry', $document);
            $document = $retry->execute($document, $context->membership());
            $this->logSubject($document);

            return (new DocumentResource($document->load(['member', 'pdf', 'docx'])))->response()->setStatusCode(202);
        }, action: 'documents.retry');
    }

    public function regenerate(GeneratedDocument $document, TenantContext $context, RegenerateDocument $regenerate): JsonResponse
    {
        return $this->handle(function () use ($document, $context, $regenerate): JsonResponse {
            Gate::authorize('regenerate', $document);
            $version = $regenerate->execute($document, $context->membership());
            $this->logSubject($version);

            return (new DocumentResource($version->load(['member', 'pdf', 'docx'])))->response()->setStatusCode(202);
        }, action: 'documents.regenerate');
    }

    public function cancel(CancelDocumentRequest $request, GeneratedDocument $document, CancelDocument $cancel): DocumentResource|JsonResponse
    {
        return $this->handle(function () use ($request, $document, $cancel): DocumentResource {
            $document = $cancel->execute($document, $request->reason(), $this->currentUser());
            $this->logSubject($document);

            return new DocumentResource($document->load(['member', 'pdf', 'docx']));
        }, action: 'documents.cancel');
    }
}
