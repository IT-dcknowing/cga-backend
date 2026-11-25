<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Http\Resources\MemberDocumentResource;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/member/documents : « Mes documents » de l'adhérent (/adherent/documents, RG-DOC-04) —
 * contrat, attestations, reçus et documents déposés par le CGA, valides ou annulés, en PDF seulement ;
 * le courrier de radiation au CDI reste au centre. Ouvert aussi au dossier radié pendant sa
 * consultation seule (RG-ADH-12).
 */
class MemberSpaceDocumentController extends BaseApiController
{
    public function index(TenantContext $context): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function () use ($context): AnonymousResourceCollection {
            Gate::authorize('viewOwn', GeneratedDocument::class);

            return MemberDocumentResource::collection(GeneratedDocument::query()
                ->with('pdf')
                ->where('member_id', $context->memberId())
                ->where('visible_to_member', true)
                ->whereIn('status', [DocumentStatus::Valid, DocumentStatus::Cancelled])
                ->orderByDesc('issued_at')
                ->orderByDesc('created_at')
                ->get());
        }, action: 'member.documents.index');
    }
}
