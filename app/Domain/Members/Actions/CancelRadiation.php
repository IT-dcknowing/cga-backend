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
use App\Domain\Members\Events\RadiationCancelled;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Members\Models\PortfolioMovement;
use App\Domain\Members\Models\Radiation;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Annuler la radiation » (F-ADH-07, RG-ADH-11), par le gérant seulement, avec motif : à tout moment
 * tant qu'elle n'est que demandée, dans les members.radiation_cancel_days jours qui suivent sa
 * validation sinon. Le dossier est restauré à l'identique : statut, portefeuille CGA et apporteur
 * d'un dossier basculé, contrat CGA en cours, accès en ligne ; la radiation sort de l'état des
 * résiliations du trimestre. Les échéances et le rapport déjà transmis se restaurent par
 * RadiationCancelled. Un dossier rouvert ne doit pas doubler une adhésion prise entre-temps (NCC
 * rouvert dans le centre, exclusivité RG-ADH-05).
 */
class CancelRadiation
{
    public function __construct(
        private readonly AssertMembershipExclusivity $exclusivity,
        private readonly SettingsService $settings,
        private readonly CreateExpectedPieces $pieces,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Date limite d'annulation d'une radiation validée ; null tant qu'elle n'est que demandée.
     */
    public function deadline(Radiation $radiation): ?CarbonImmutable
    {
        return $radiation->validated_at === null
            ? null
            : CarbonImmutable::parse($radiation->validated_at)->addDays((int) $this->settings->get('members.radiation_cancel_days', $radiation->organization_id));
    }

    /**
     * @throws BusinessRuleException radiation_not_found, radiation_cancel_expired, ncc_already_registered, membership_exclusive
     */
    public function execute(Member $member, string $reason, User $actor): Radiation
    {
        return DB::transaction(function () use ($member, $reason, $actor): Radiation {
            $member = Member::query()->lockForUpdate()->findOrFail($member->id);
            $radiation = Radiation::query()->where('member_id', $member->id)->whereNull('cancelled_at')->latest('requested_at')->lockForUpdate()->first();
            if ($radiation === null || ! $this->inForce($member, $radiation)) {
                throw new BusinessRuleException('radiation_not_found');
            }
            $deadline = $this->deadline($radiation);
            if ($deadline !== null && now()->greaterThan($deadline)) {
                throw new BusinessRuleException('radiation_cancel_expired', details: ['deadline' => $deadline->toIso8601ZuluString()]);
            }

            $validated = $radiation->status === RadiationStatus::Validated;
            if ($validated) {
                $this->reopen($member, $radiation);
            }
            $member->status = MemberStatus::Active;
            $member->updated_by = $actor->id;
            $member->save();

            $radiation->status = RadiationStatus::Cancelled;
            $radiation->cancelled_at = now()->toImmutable();
            $radiation->cancelled_by = $actor->id;
            $radiation->cancel_reason = $reason;
            $radiation->save();

            RadiationCancelled::dispatch($radiation, $validated);
            $this->audit->record('radiation_cancelled', $member, context: ['radiation_id' => $radiation->id, 'was_validated' => $validated]);

            return $radiation->refresh();
        });
    }

    /**
     * La radiation est toujours celle qui a fait sortir le dossier du portefeuille CGA : une bascule
     * dont le dossier est depuis revenu au portefeuille CGA ne s'annule plus (RG-PTF-03).
     */
    public function inForce(Member $member, Radiation $radiation): bool
    {
        return $radiation->outcome !== RadiationOutcome::MovedToCabinet || $member->portfolio === Portfolio::Cabinet;
    }

    /**
     * Effets d'une radiation validée défaits : le contrat CGA reprend, le dossier revient au
     * portefeuille CGA, avec son apporteur, ou rouvre ses accès en ligne.
     *
     * @throws BusinessRuleException ncc_already_registered, membership_exclusive
     */
    private function reopen(Member $member, Radiation $radiation): void
    {
        if ($radiation->outcome === RadiationOutcome::Closed) {
            AssertNccAvailable::here($member->ncc, $member);
        }
        $this->exclusivity->execute($member->ncc, $member->organization_id);

        $member->cga_contract_ended_on = null;
        if ($radiation->outcome === RadiationOutcome::Closed) {
            $this->reopenAccesses($member);

            return;
        }

        $movement = PortfolioMovement::query()->where('radiation_id', $radiation->id)->where('direction', MovementDirection::CgaToCabinet)->first();
        $member->portfolio = Portfolio::Cga;
        $member->referrer_id = $movement?->previous_referrer_id;
        $member->save();
        $movement?->delete();

        $contract = MemberPiece::query()->where('member_id', $member->id)->where('piece_type', PieceType::SignedCgaContract)->first();
        if ($contract !== null) {
            $contract->status = $contract->file_id === null ? PieceStatus::Expected : PieceStatus::Received;
            $contract->save();
        }
        $this->pieces->refreshStatus($member);
    }

    /**
     * Accès en consultation seule rendus : actifs s'ils l'étaient, sinon de nouveau en invitation.
     */
    private function reopenAccesses(Member $member): void
    {
        $accesses = Membership::query()->where('member_id', $member->id)->where('status', MembershipStatus::ReadOnly)->get();
        foreach ($accesses as $access) {
            $access->status = $access->activated_at === null ? MembershipStatus::Invited : MembershipStatus::Active;
            $access->read_only_until = null;
            $access->save();
        }
    }
}
