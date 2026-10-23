<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Jobs\GenerateDocument;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\VerificationCode;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Émission d'un document (02-TRD §8.2, RG-DOC-03, 05) : la ligne est créée « en production » avec son
 * numéro, sa version (la suivante sous ce numéro), son code de vérification et le modèle actif de son
 * type, dans la transaction de
 * l'Action qui l'émet ; le Job GenerateDocument produit ensuite le DOCX et le PDF (file « documents »)
 * une fois la transaction validée, sans faire attendre l'utilisateur. Bloquée tant que la fiche du CGA
 * est incomplète (RG-ORG-04, avec la liste des champs manquants).
 */
class IssueDocument
{
    public function __construct(private readonly VerificationCode $codes) {}

    /**
     * @throws BusinessRuleException cga_profile_incomplete, template_missing
     */
    public function execute(DocumentRequest $request, Membership $requester): GeneratedDocument
    {
        return DB::transaction(function () use ($request, $requester): GeneratedDocument {
            $this->ensureProfileComplete($requester->organization_id);

            $template = DocumentTemplate::query()->where('type', $request->type)->where('is_active', true)->first()
                ?? throw new BusinessRuleException('template_missing');

            $document = new GeneratedDocument;
            $document->organization_id = $requester->organization_id;
            $document->member_id = $request->memberId;
            $document->type = $request->type;
            $document->title = $request->title;
            $document->number = $request->number;
            $document->version = $this->version($request, $requester->organization_id);
            $document->replaces_document_id = $request->replacesDocumentId;
            $document->template_id = $template->id;
            $document->verification_token = $this->codes->generate();
            $document->status = DocumentStatus::Generating;
            $document->issued_at = now()->toImmutable();
            $document->valid_until = $request->validUntil;
            $document->fiscal_year = $request->fiscalYear;
            $document->visible_to_member = $request->visibleToMember;
            $document->source_type = $request->sourceType;
            $document->source_id = $request->sourceId;
            $document->generated_by = $requester->user_id;
            $document->save();

            GenerateDocument::dispatch($document->id, $requester->id)->afterCommit();

            return $document;
        });
    }

    /**
     * Version suivante sous ce numéro (RG-DOC-03) : 1 pour un premier document, n + 1 pour une
     * régénération, un duplicata ou un document reproduit après annulation.
     */
    private function version(DocumentRequest $request, string $organizationId): int
    {
        if ($request->number === null) {
            return 1;
        }

        $last = GeneratedDocument::query()
            ->where('organization_id', $organizationId)
            ->where('type', $request->type)
            ->where('number', $request->number)
            ->max('version');

        return (int) $last + 1;
    }

    /**
     * RG-ORG-04, contrôlé avant toute autre règle d'un document : la fiche du centre est complète.
     *
     * @throws BusinessRuleException cga_profile_incomplete, avec les champs manquants
     */
    public function ensureProfileComplete(string $organizationId): void
    {
        $missing = Organization::query()->findOrFail($organizationId)->missingProfileFields();
        if ($missing !== []) {
            throw new BusinessRuleException('cga_profile_incomplete', details: ['missing' => $missing]);
        }
    }
}
