<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\AttestationBlockTrigger;
use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Support\MemberAlerts;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Blocage manuel de la délivrance de l'attestation d'un adhérent (RG-DOC-10), décidé par le gérant —
 * jamais par la plateforme (CR-04) : sur une alerte en retard (honoraires impayés RG-PAY-05, RSI non
 * radié RG-ADH-09) ou de sa propre initiative, motif obligatoire. Seul un dossier du portefeuille CGA,
 * validé, a une attestation ; un seul blocage en cours à la fois.
 */
class BlockAttestation
{
    public function __construct(private readonly MemberAlerts $alerts) {}

    /**
     * @throws BusinessRuleException attestation_block_unavailable, attestation_already_blocked
     * @throws ValidationException alerte invoquée absente ou pas encore en retard
     */
    public function execute(Member $member, AttestationBlockTrigger $trigger, string $reason, User $actor): AttestationBlock
    {
        return DB::transaction(function () use ($member, $trigger, $reason, $actor): AttestationBlock {
            $member = Member::query()->with('overdueFees')->lockForUpdate()->findOrFail($member->id);
            if ($member->portfolio !== Portfolio::Cga || ! in_array($member->status, [MemberStatus::Active, MemberStatus::RadiationPending], true)) {
                throw new BusinessRuleException('attestation_block_unavailable');
            }
            if ($member->attestation_blocked) {
                throw new BusinessRuleException('attestation_already_blocked');
            }

            $alert = match ($trigger) {
                AttestationBlockTrigger::FeesOverdue => MemberAlerts::FEES_OVERDUE,
                AttestationBlockTrigger::RsiOverdue => MemberAlerts::RADIATION_REQUIRED,
                AttestationBlockTrigger::Manual => null,
            };
            if ($alert !== null && ! $this->alerts->overdue($member, $alert)) {
                throw ValidationException::withMessages(['trigger' => __('documents.block_alert_missing')]);
            }

            $block = new AttestationBlock;
            $block->organization_id = $member->organization_id;
            $block->member_id = $member->id;
            $block->trigger = $trigger;
            $block->reason = $reason;
            $block->blocked_by = $actor->id;
            $block->blocked_at = now()->toImmutable();
            $block->save();

            $member->attestation_blocked = true;
            $member->save();

            return $block;
        });
    }
}
