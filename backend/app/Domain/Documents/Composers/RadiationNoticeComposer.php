<?php

namespace App\Domain\Documents\Composers;

use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Members\Models\Radiation;
use App\Domain\Organizations\Models\Organization;

/**
 * Données du courrier de notification de radiation au CDI de rattachement (F-ADH-06, effet 3, modèle
 * radiation_notice) : n° du courrier, CDI destinataire, adhérent radié, initiative, motif, date d'effet,
 * date et référence de la validation.
 */
class RadiationNoticeComposer implements DocumentComposer
{
    public function __construct(private readonly CgaIdentity $identity) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $organization = Organization::query()->with(['logo', 'signature', 'stamp'])->findOrFail($document->organization_id);
        $radiation = Radiation::query()->with('member.taxOffice')->findOrFail($document->source_id);
        $member = $radiation->member;
        $reason = $radiation->reason->label().($radiation->reason_other === null ? '' : ' : '.$radiation->reason_other);

        return new DocumentContent([
            ...$this->identity->values($organization),
            'notice_number' => DocumentFormat::text($document->number),
            'tax_office_name' => DocumentFormat::text($member->taxOffice?->name),
            'member_name' => $member->company_name,
            'member_ncc' => $member->ncc,
            'membership_number' => DocumentFormat::text($member->membership_number),
            'adhesion_date' => DocumentFormat::shortDate($member->adhesion_date),
            'member_tax_regime' => $member->regime->label(),
            'radiation_effective_date' => DocumentFormat::date($radiation->effective_date),
            'radiation_initiator' => $radiation->initiator->label(),
            'radiation_reason' => $reason,
            'radiation_validated_on' => DocumentFormat::date($radiation->validated_at),
            'radiation_reference' => DocumentFormat::text($radiation->validation_reference ?? $radiation->sp_request_reference),
            'document_date' => DocumentFormat::date($document->issued_at),
        ], $this->identity->images($organization));
    }
}
