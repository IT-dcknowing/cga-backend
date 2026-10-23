<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentNumbering;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Models\Radiation;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Courrier de notification d'une radiation validée au CDI de rattachement (F-ADH-06, effet 3), à
 * remettre par le CGA : numéroté NOT-ABR-AAAA-NNNNN, réservé au centre (l'adhérent ne le voit pas) ;
 * produit à la validation (ProduceRadiationNoticeOnValidation) ou, si la fiche du centre était alors
 * incomplète, à la demande. Une radiation n'a qu'un courrier en cours.
 */
class ProduceRadiationNotice
{
    public function __construct(
        private readonly IssueDocument $issue,
        private readonly DocumentNumbering $numbering,
    ) {}

    /**
     * @throws BusinessRuleException radiation_notice_unavailable, radiation_notice_already_issued, cga_profile_incomplete, template_missing, abbreviation_missing
     */
    public function execute(Radiation $radiation, Membership $requester): GeneratedDocument
    {
        return DB::transaction(function () use ($radiation, $requester): GeneratedDocument {
            $this->issue->ensureProfileComplete($requester->organization_id);
            $radiation = Radiation::query()->with('member')->lockForUpdate()->findOrFail($radiation->id);
            if ($radiation->status !== RadiationStatus::Validated) {
                throw new BusinessRuleException('radiation_notice_unavailable');
            }

            $issued = GeneratedDocument::query()
                ->where('type', DocumentType::RadiationNotice)
                ->where('source_type', 'radiation')
                ->where('source_id', $radiation->id)
                ->where('status', '<>', DocumentStatus::Cancelled)
                ->exists();
            if ($issued) {
                throw new BusinessRuleException('radiation_notice_already_issued');
            }

            return $this->issue->execute(new DocumentRequest(
                type: DocumentType::RadiationNotice,
                title: (string) __('documents.title', ['type' => DocumentType::RadiationNotice->label(), 'member' => $radiation->member->company_name]),
                memberId: $radiation->member_id,
                number: $this->numbering->next($radiation->organization_id, DocumentType::RadiationNotice),
                visibleToMember: false,
                sourceType: 'radiation',
                sourceId: $radiation->id,
            ), $requester);
        });
    }
}
