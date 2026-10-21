<?php

namespace App\Domain\Members\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\MovementDirection;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\RadiationOutcome;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Events\RadiationValidated;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Members\Models\PortfolioMovement;
use App\Domain\Members\Models\Radiation;
use App\Domain\Members\Support\MemberNumbering;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Numbering\SequenceService;
use Illuminate\Support\Facades\DB;

/**
 * « Valider la radiation » (F-ADH-06), second temps de RG-ADH-17, toujours par un utilisateur habilité
 * (RG-ADH-18). Effets dans la même transaction, tout ou rien :
 *  1. dossier radié (« clore ») ou basculé dans le portefeuille Cabinet (RG-PTF-04 : n° client attribué
 *     s'il n'en a pas, fin du rattachement à l'apporteur, contrat CGA sans objet, bascule historisée) ;
 *     le contrat CGA prend fin à la date d'effet ;
 *  2. radiation rangée dans l'état des résiliations du trimestre de la date d'effet ;
 *  5. à la clôture, accès en ligne de l'adhérent en consultation seule pendant members.read_only_months
 *     (RG-ADH-12) ; un dossier basculé reste suivi par le cabinet et garde ses accès.
 * Les effets 3 (courrier au CDI), 4 (échéances futures annulées) et 6 (notification à l'adhérent)
 * s'abonnent à RadiationValidated. La validation lève le blocage d'exclusivité dans les autres CGA
 * (RG-ADH-10) : le dossier n'est plus actif au portefeuille CGA.
 */
class ValidateRadiation
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly SettingsService $settings,
        private readonly MemberNumbering $numbering,
        private readonly CreateExpectedPieces $pieces,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws BusinessRuleException radiation_not_requested
     */
    public function execute(Member $member, RadiationOutcome $outcome, ?string $reference, User $actor): Radiation
    {
        return DB::transaction(function () use ($member, $outcome, $reference, $actor): Radiation {
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            $radiation = Radiation::query()->where('member_id', $member->id)->where('status', RadiationStatus::Requested)->lockForUpdate()->first();
            if ($radiation === null || $member->status !== MemberStatus::RadiationPending) {
                throw new BusinessRuleException('radiation_not_requested');
            }

            $radiation->status = RadiationStatus::Validated;
            $radiation->validated_by = $actor->id;
            $radiation->validated_at = now()->toImmutable();
            $radiation->validation_reference = $reference;
            $radiation->outcome = $outcome;
            $radiation->report_year = $radiation->effective_date->year;
            $radiation->report_quarter = $radiation->effective_date->quarter;
            $radiation->save();

            $member->cga_contract_ended_on = $radiation->effective_date;
            $member->updated_by = $actor->id;
            if ($outcome === RadiationOutcome::Closed) {
                $member->status = MemberStatus::Radiated;
                $member->save();
                $this->restrictAccesses($member);
            } else {
                $this->moveToCabinet($member, $radiation, $actor);
            }

            RadiationValidated::dispatch($radiation);
            $this->audit->record('radiation_validated', $member, context: ['radiation_id' => $radiation->id, 'outcome' => $outcome->value, 'reference' => $reference]);

            return $radiation->refresh();
        });
    }

    /**
     * Accès en ligne de l'adhérent radié : ses propres documents, en consultation seule (RG-ADH-12).
     */
    private function restrictAccesses(Member $member): void
    {
        $until = today()->addMonths((int) $this->settings->get('members.read_only_months', $member->organization_id));
        $accesses = Membership::query()->where('member_id', $member->id)->whereIn('status', [MembershipStatus::Active, MembershipStatus::Invited])->get();
        foreach ($accesses as $access) {
            $access->status = MembershipStatus::ReadOnly;
            $access->read_only_until = $until;
            $access->save();
        }
    }

    /**
     * Bascule CGA → Cabinet (RG-PTF-04, 05) : la sortie du portefeuille CGA passe toujours par ici.
     */
    private function moveToCabinet(Member $member, Radiation $radiation, User $actor): void
    {
        if ($member->client_number === null) {
            $abbreviation = Organization::query()->whereKey($member->organization_id)->value('numbering_abbreviation');
            $number = $this->sequences->next($member->organization_id, MemberNumbering::CLIENT);
            $member->client_number = $this->numbering->clientNumber($number, is_string($abbreviation) ? $abbreviation : null);
        }
        $previousReferrer = $member->referrer_id;
        $member->portfolio = Portfolio::Cabinet;
        $member->referrer_id = null;
        $member->status = MemberStatus::Active;
        $member->save();

        $contract = MemberPiece::query()->where('member_id', $member->id)->where('piece_type', PieceType::SignedCgaContract)->first();
        if ($contract !== null) {
            $contract->status = PieceStatus::NotApplicable;
            $contract->save();
        }
        $this->pieces->refreshStatus($member);

        $movement = new PortfolioMovement;
        $movement->organization_id = $member->organization_id;
        $movement->member_id = $member->id;
        $movement->direction = MovementDirection::CgaToCabinet;
        $movement->effective_date = $radiation->effective_date;
        $movement->reason = $radiation->reason_other ?? $radiation->reason->label();
        $movement->radiation_id = $radiation->id;
        $movement->previous_referrer_id = $previousReferrer;
        $movement->created_by = $actor->id;
        $movement->save();
    }
}
