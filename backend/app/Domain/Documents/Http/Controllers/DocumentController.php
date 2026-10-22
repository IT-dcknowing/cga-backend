<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Actions\RetryDocument;
use App\Domain\Documents\Http\Resources\DocumentResource;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/documents/{document} : un document du centre — état de sa production (ligne « en
 * production » remplacée quand il est prêt, RG-DOC-05), liens signés du PDF et du Word, relance d'une
 * production en échec.
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
}
