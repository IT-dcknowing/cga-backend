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
 * Production du contrat d'adhésion (F-DOC-01, RG-ADH-06) : pour un dossier validé du portefeuille CGA
 * (un client du portefeuille Cabinet n'a pas de contrat CGA), numéroté du n° d'adhésion, signé par le
 * gérant. Un dossier n'a qu'un contrat en cours : un contrat émis se régénère (RG-ADH-07) et une
 * production en échec se relance ; seul un contrat annulé laisse produire un nouveau contrat.
 */
class ProduceContract
{
    public function __construct(private readonly IssueDocument $issue) {}

    /**
     * @throws BusinessRuleException contract_unavailable, contract_already_issued, cga_profile_incomplete, template_missing
     */
    public function execute(Member $member, Membership $requester): GeneratedDocument
    {
        return DB::transaction(function () use ($member, $requester): GeneratedDocument {
            $this->issue->ensureProfileComplete($requester->organization_id);
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            DocumentEligibility::contract($member);

            $issued = GeneratedDocument::query()
                ->where('member_id', $member->id)
                ->where('type', DocumentType::MembershipContract)
                ->where('status', '<>', DocumentStatus::Cancelled)
                ->exists();
            if ($issued) {
                throw new BusinessRuleException('contract_already_issued');
            }

            return $this->issue->execute(new DocumentRequest(
                type: DocumentType::MembershipContract,
                title: (string) __('documents.title', ['type' => DocumentType::MembershipContract->label(), 'member' => $member->company_name]),
                memberId: $member->id,
                number: $member->membership_number,
                sourceType: 'member',
                sourceId: $member->id,
            ), $requester);
        });
    }
}
