<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Actions\ProduceMemberDocument;
use App\Domain\Documents\Actions\UploadDocument;
use App\Domain\Documents\Http\Requests\ProduceDocumentRequest;
use App\Domain\Documents\Http\Requests\UploadDocumentRequest;
use App\Domain\Documents\Http\Resources\DocumentResource;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/cga/members/{member}/documents : onglet « Documents » du dossier (03-APPFLOW §4) — documents
 * émis ou déposés, du plus récent au plus ancien ; production à la demande (F-DOC-01), asynchrone
 * (RG-DOC-05) : 202 et le document « en production », à suivre par GET /api/v1/cga/documents/{document} ;
 * dépôt d'un PDF dans l'espace de l'adhérent (F-DOC-05).
 */
class MemberDocumentController extends BaseApiController
{
    public function index(Member $member): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($member): AnonymousResourceCollection {
            Gate::authorize('viewAny', [GeneratedDocument::class, $member]);

            return DocumentResource::collection(GeneratedDocument::query()
                ->with(['pdf', 'docx'])
                ->where('member_id', $member->id)
                ->orderByDesc('issued_at')
                ->orderByDesc('created_at')
                ->get());
        }, action: 'documents.index');
    }

    public function store(ProduceDocumentRequest $request, Member $member, TenantContext $context, ProduceMemberDocument $produce): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $context, $produce): JsonResponse {
            $document = $produce->execute($member, $request->type(), $request->paymentId(), $context->membership());
            $this->logSubject($document);

            return (new DocumentResource($document->load(['member', 'pdf', 'docx'])))->response()->setStatusCode(202);
        }, action: 'documents.store');
    }

    public function upload(UploadDocumentRequest $request, Member $member, TenantContext $context, UploadDocument $upload): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $context, $upload): JsonResponse {
            $document = $upload->execute($member, $request->title(), $request->category(), $request->pdf(), $context->membership());
            $this->logSubject($document);

            return (new DocumentResource($document->load(['member', 'pdf', 'docx'])))->response()->setStatusCode(201);
        }, action: 'documents.upload');
    }
}
