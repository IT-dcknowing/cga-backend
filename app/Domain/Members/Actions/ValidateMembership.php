<?php

namespace App\Domain\Members\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Support\MemberNumbering;
use App\Domain\Members\Support\MembershipReadiness;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Numbering\SequenceService;
use Illuminate\Support\Facades\DB;

/**
 * « Valider l'adhésion » (F-ADH-02, RG-ADH-02, 03) : tout ce qui est exigé est rempli, sinon la liste
 * de ce qui manque (409) ; au portefeuille CGA, l'exclusivité est vérifiée sous verrou (RG-ADH-05) et
 * l'absence d'adhésion dans un CGA non abonné confirmée sur l'honneur, tracée. Le numéro CGA Connect
 * est attribué dans la transaction, séquence continue du centre sans doublon ni trou (verrou du
 * compteur) : AAAA-ABR-NNNN au portefeuille CGA (année de la date d'adhésion), CAB-ABR-NNNNN au
 * Cabinet. L'abréviation du CGA est figée au premier numéro (RG-ORG-05). Les pièces attendues sont
 * créées ; elles ne bloquent pas (RG-PJ-02).
 */
class ValidateMembership
{
    public function __construct(
        private readonly MembershipReadiness $readiness,
        private readonly AssertMembershipExclusivity $exclusivity,
        private readonly SequenceService $sequences,
        private readonly MemberNumbering $numbering,
        private readonly CreateExpectedPieces $pieces,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  bool  $exclusivityConfirmed  confirmation sur l'honneur : pas d'adhésion dans un autre CGA
     *
     * @throws BusinessRuleException member_not_draft, membership_incomplete, membership_exclusive, abbreviation_missing
     */
    public function execute(Member $member, bool $exclusivityConfirmed, User $actor): Member
    {
        return DB::transaction(function () use ($member, $exclusivityConfirmed, $actor): Member {
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            if ($member->status !== MemberStatus::Draft) {
                throw new BusinessRuleException('member_not_draft');
            }

            $missing = $this->readiness->missing($member);
            $cga = $member->portfolio === Portfolio::Cga;
            if ($cga && ! $exclusivityConfirmed) {
                $missing[] = 'exclusivity_confirmed';
            }
            if ($missing !== []) {
                throw new BusinessRuleException('membership_incomplete', details: ['missing' => $missing]);
            }
            if ($cga) {
                $this->exclusivity->execute($member->ncc, $member->organization_id);
            }

            $organization = Organization::query()->lockForUpdate()->findOrFail($member->organization_id);
            if ($organization->numbering_abbreviation === null) {
                throw new BusinessRuleException('abbreviation_missing');
            }

            $member->adhesion_date = $cga ? $member->cga_contract_signed_on : today();
            $number = $this->sequences->next($member->organization_id, $cga ? MemberNumbering::MEMBERSHIP : MemberNumbering::CLIENT);
            if ($cga) {
                $member->membership_seq = $number;
                $member->membership_number = $this->numbering->membershipNumber($number, $member->adhesion_date?->year, $organization->numbering_abbreviation);
            } else {
                $member->client_number = $this->numbering->clientNumber($number, $organization->numbering_abbreviation);
            }
            $member->status = MemberStatus::Active;
            $member->validated_at = now();
            $member->validated_by = $actor->id;
            $member->updated_by = $actor->id;
            $member->save();

            if ($organization->abbreviation_locked_at === null) {
                $organization->abbreviation_locked_at = now();
                $organization->save();
            }

            $this->pieces->execute($member);
            $this->audit->record('membership_validated', $member, context: [
                'number' => $member->membership_number ?? $member->client_number,
                'exclusivity_confirmed' => $cga ? $exclusivityConfirmed : null,
            ]);

            return $member->refresh();
        });
    }
}
