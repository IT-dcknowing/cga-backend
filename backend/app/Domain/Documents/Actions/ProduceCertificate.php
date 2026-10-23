<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentEligibility;
use App\Domain\Documents\Support\DocumentNumbering;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Attestation d'adhésion de l'année civile (F-DOC-02, RG-DOC-06, 07) : une attestation d'origine par
 * adhérent et par année, numérotée ATT-ABR-AAAA-NNNNN, valable documents.certificate_validity_months à
 * compter de son émission ; un duplicata se réédite à partir d'elle. Refusée pour un dossier radié, en
 * radiation, du portefeuille Cabinet, ou dont l'attestation est bloquée (RG-DOC-10).
 */
class ProduceCertificate
{
    public function __construct(
        private readonly IssueDocument $issue,
        private readonly DocumentNumbering $numbering,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @throws BusinessRuleException certificate_unavailable, attestation_blocked, certificate_already_issued, cga_profile_incomplete, template_missing, abbreviation_missing
     */
    public function execute(Member $member, Membership $requester): GeneratedDocument
    {
        return DB::transaction(function () use ($member, $requester): GeneratedDocument {
            $this->issue->ensureProfileComplete($requester->organization_id);
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            DocumentEligibility::certificate($member);

            $year = today()->year;
            $issued = GeneratedDocument::query()
                ->where('member_id', $member->id)
                ->where('type', DocumentType::MembershipCertificate)
                ->where('fiscal_year', $year)
                ->where('version', 1)
                ->where('status', '<>', DocumentStatus::Cancelled)
                ->exists();
            if ($issued) {
                throw new BusinessRuleException('certificate_already_issued', details: ['year' => $year]);
            }

            $months = (int) $this->settings->get('documents.certificate_validity_months', $member->organization_id);

            return $this->issue->execute(new DocumentRequest(
                type: DocumentType::MembershipCertificate,
                title: (string) __('documents.certificate_title', ['type' => DocumentType::MembershipCertificate->label(), 'year' => $year, 'member' => $member->company_name]),
                memberId: $member->id,
                number: $this->numbering->next($member->organization_id, DocumentType::MembershipCertificate, $year),
                validUntil: today()->addMonthsNoOverflow($months)->toImmutable(),
                fiscalYear: $year,
                sourceType: 'member',
                sourceId: $member->id,
            ), $requester);
        });
    }
}
