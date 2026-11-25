<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentEligibility;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Régénération d'un contrat après un changement de la fiche (RG-ADH-07) ou réédition d'une attestation
 * (duplicata, RG-DOC-06) : nouvelle version sous le même numéro, avec les données du moment, la même
 * validité et le même exercice ; la version précédente reste valide tant qu'elle n'est pas annulée. On
 * régénère la dernière version, pour un dossier qui y a toujours droit (RG-DOC-07).
 */
class RegenerateDocument
{
    /**
     * Types qui se régénèrent ; les autres se produisent à leur événement.
     */
    public const TYPES = [DocumentType::MembershipContract, DocumentType::MembershipCertificate];

    public function __construct(private readonly IssueDocument $issue) {}

    /**
     * @throws BusinessRuleException document_not_regenerable, document_not_valid, document_superseded, contract_unavailable, certificate_unavailable, attestation_blocked, cga_profile_incomplete, template_missing
     */
    public function execute(GeneratedDocument $document, Membership $requester): GeneratedDocument
    {
        return DB::transaction(function () use ($document, $requester): GeneratedDocument {
            $document = GeneratedDocument::query()->lockForUpdate()->findOrFail($document->id);
            if (! in_array($document->type, self::TYPES, true) || $document->member_id === null) {
                throw new BusinessRuleException('document_not_regenerable');
            }
            if ($document->status !== DocumentStatus::Valid) {
                throw new BusinessRuleException('document_not_valid');
            }
            $superseded = GeneratedDocument::query()
                ->where('replaces_document_id', $document->id)
                ->where('status', '<>', DocumentStatus::Cancelled)
                ->exists();
            if ($superseded) {
                throw new BusinessRuleException('document_superseded');
            }

            $this->issue->ensureProfileComplete($requester->organization_id);
            $member = Member::query()->lockForUpdate()->findOrFail($document->member_id);
            $document->type === DocumentType::MembershipContract ? DocumentEligibility::contract($member) : DocumentEligibility::certificate($member);

            return $this->issue->execute(new DocumentRequest(
                type: $document->type,
                title: $document->title,
                memberId: $document->member_id,
                number: $document->number,
                replacesDocumentId: $document->id,
                validUntil: $document->valid_until,
                fiscalYear: $document->fiscal_year,
                visibleToMember: $document->visible_to_member,
                sourceType: $document->source_type,
                sourceId: $document->source_id,
            ), $requester);
        });
    }
}
