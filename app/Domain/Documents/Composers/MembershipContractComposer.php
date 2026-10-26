<?php

namespace App\Domain\Documents\Composers;

use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Support\Phones\PhoneNormalizer;

/**
 * Données du contrat d'adhésion (F-DOC-01, modèle membership_contract) : identité du CGA et de son
 * signataire, identité de l'adhérent telle que la fiche la porte au moment de la production, date
 * d'émission du document.
 */
class MembershipContractComposer implements DocumentComposer
{
    public function __construct(
        private readonly CgaIdentity $identity,
        private readonly PhoneNormalizer $phones,
    ) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $organization = Organization::query()->with(['logo', 'signature', 'stamp'])->findOrFail($document->organization_id);
        $member = Member::query()->with(['commune', 'activityCode'])->findOrFail($document->member_id);

        return new DocumentContent([
            ...$this->identity->values($organization),
            ...$this->member($member),
            'document_date' => DocumentFormat::date($document->issued_at),
        ], $this->identity->images($organization));
    }

    /**
     * @return array<string, string>
     */
    private function member(Member $member): array
    {
        $owner = trim($member->owner_last_name.' '.$member->owner_first_names);
        $signatory = $member->signatory_name === null
            ? DocumentFormat::text($owner)
            : (string) __('documents.signatory', ['name' => $member->signatory_name, 'title' => DocumentFormat::text($member->signatory_title)]);

        return [
            'member_name' => $member->company_name,
            'member_legal_form' => $member->legal_form->label(),
            'member_sigle' => DocumentFormat::text($member->sigle),
            'member_share_capital' => DocumentFormat::amount($member->share_capital),
            'member_address' => DocumentFormat::text(implode(', ', array_filter([$member->address, $member->commune?->name]))),
            'member_section' => DocumentFormat::text($member->land_section),
            'member_parcel' => DocumentFormat::text($member->land_parcel),
            'member_phone' => $this->phones->display($member->phone),
            'member_fax' => DocumentFormat::text(null),
            'member_email' => DocumentFormat::text($member->email),
            'member_activities' => $member->main_activity,
            'member_rccm' => DocumentFormat::text($member->rccm),
            'member_ncc' => $member->ncc,
            'member_tax_regime' => $member->regime->label(),
            'member_activity_code' => DocumentFormat::text($member->activityCode->code ?? $member->activity_code_text),
            'member_signatory' => $signatory,
        ];
    }
}
