<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Actions\ProduceContract;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Http\Requests\ProduceDocumentRequest;
use App\Domain\Documents\Http\Resources\DocumentResource;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * /api/v1/cga/members/{member}/documents : onglet « Documents » du dossier (03-APPFLOW §4) — documents
 * émis, du plus récent au plus ancien, et production à la demande (F-DOC-01). La production est
 * asynchrone (RG-DOC-05) : 202 et le document « en production », à suivre par
 * GET /api/v1/cga/documents/{document}.
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

    public function store(ProduceDocumentRequest $request, Member $member, TenantContext $context, ProduceContract $contract): JsonResponse
    {
        return $this->handle(function () use ($request, $member, $context, $contract): JsonResponse {
            $document = match ($request->type()) {
                DocumentType::MembershipContract => $contract->execute($member, $context->membership()),
                default => throw new LogicException('Document non produit à la demande : '.$request->type()->value),
            };
            $this->logSubject($document);

            return (new DocumentResource($document->load(['member', 'pdf', 'docx'])))->response()->setStatusCode(202);
        }, action: 'documents.store');
    }
}
