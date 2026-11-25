<?php

namespace App\Domain\Documents\Composers;

use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Support\Phones\PhoneNormalizer;

/**
 * Données de l'attestation d'adhésion (F-DOC-02, modèle membership_certificate) : identité et logo du
 * CGA, numéro et année de l'attestation, n° et date d'adhésion, identité de l'adhérent et son CDI de
 * rattachement.
 */
class MembershipCertificateComposer implements DocumentComposer
{
    public function __construct(
        private readonly CgaIdentity $identity,
        private readonly PhoneNormalizer $phones,
    ) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $organization = Organization::query()->with(['logo', 'signature', 'stamp'])->findOrFail($document->organization_id);
        $member = Member::query()->with(['commune', 'taxOffice'])->findOrFail($document->member_id);

        return new DocumentContent([
            ...$this->identity->values($organization),
            'certificate_number' => DocumentFormat::text($document->number),
            'certificate_year' => DocumentFormat::text($document->fiscal_year === null ? null : (string) $document->fiscal_year),
            'membership_number' => DocumentFormat::text($member->membership_number),
            'membership_date' => DocumentFormat::shortDate($member->adhesion_date),
            'member_name' => $member->company_name,
            'member_trade_name' => DocumentFormat::text($member->sigle ?? $member->company_name),
            'member_location' => DocumentFormat::text(implode(', ', array_filter([$member->address, $member->commune?->name]))),
            'member_ncc' => $member->ncc,
            'member_legal_form' => $member->legal_form->label(),
            'member_tax_regime' => $member->regime->label(),
            'member_tax_office' => DocumentFormat::text($member->taxOffice?->name),
            'member_phone' => $this->phones->display($member->phone),
            'document_date' => DocumentFormat::date($document->issued_at),
        ], $this->identity->images($organization));
    }
}
