<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Actions\ActivateTemplate;
use App\Domain\Documents\Actions\PreviewTemplate;
use App\Domain\Documents\Actions\UploadTemplateVersion;
use App\Domain\Documents\Http\Requests\ActivateTemplateRequest;
use App\Domain\Documents\Http\Requests\UploadTemplateRequest;
use App\Domain\Documents\Http\Resources\DocumentResource;
use App\Domain\Documents\Http\Resources\DocumentTemplateResource;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Support\Http\BaseApiController;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/platform/document-templates : modèles de documents du réseau (F-ADM-02, RG-DATA-03, onglet
 * « Modèles de documents » de /plateforme) — versions par type, dépôt d'une version avec contrôle des
 * variables, prévisualisation avec des données de test, activation après validation du CNCGA. Un
 * document émis garde la version qui l'a produit.
 */
class DocumentTemplateController extends BaseApiController
{
    private const COUNT_REASON = 'Modèles de documents : nombre de documents émis avec chaque version, tous centres confondus (agrégat, F-ADM-02).';

    public function index(): AnonymousResourceCollection|JsonResponse
    {
        return $this->handle(function (): AnonymousResourceCollection {
            Gate::authorize('viewAny', DocumentTemplate::class);

            return DocumentTemplateResource::collection(Tenancy::bypass(static fn (): Collection => DocumentTemplate::query()
                ->with('file')
                ->withCount('documents')
                ->orderBy('type')
                ->orderByDesc('created_at')
                ->get(), self::COUNT_REASON));
        }, action: 'document_templates.index');
    }

    public function store(UploadTemplateRequest $request, UploadTemplateVersion $upload): JsonResponse
    {
        return $this->handle(function () use ($request, $upload): JsonResponse {
            $template = $upload->execute($request->type(), $request->version(), $request->template(), $request->notes(), $this->currentUser());
            $this->logSubject($template);

            return (new DocumentTemplateResource($this->detailed($template)))->response()->setStatusCode(201);
        }, action: 'document_templates.store');
    }

    public function preview(DocumentTemplate $template, PreviewTemplate $preview): JsonResponse
    {
        return $this->handle(function () use ($template, $preview): JsonResponse {
            Gate::authorize('preview', $template);

            return response()->json(['data' => ['file' => DocumentResource::file($preview->execute($template, $this->currentUser()))]]);
        }, action: 'document_templates.preview');
    }

    public function activate(ActivateTemplateRequest $request, DocumentTemplate $template, ActivateTemplate $activate): DocumentTemplateResource|JsonResponse
    {
        return $this->handle(function () use ($request, $template, $activate): DocumentTemplateResource {
            return new DocumentTemplateResource($this->detailed($activate->execute($template, $request->approvedOn())));
        }, action: 'document_templates.activate');
    }

    /**
     * Version avec son fichier et le nombre de documents émis avec elle.
     */
    private function detailed(DocumentTemplate $template): DocumentTemplate
    {
        return Tenancy::bypass(static fn (): DocumentTemplate => $template->load('file')->loadCount('documents'), self::COUNT_REASON);
    }
}
