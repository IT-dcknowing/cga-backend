<?php

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Support\Http\Exceptions\BusinessRuleException;

/**
 * Dossiers qui reçoivent un contrat ou une attestation d'adhésion, à la production comme à la
 * régénération : un dossier validé du portefeuille CGA (un client du portefeuille Cabinet n'a ni
 * contrat ni attestation CGA) ; pour l'attestation, ni radié, ni en radiation, ni bloqué (RG-DOC-07) —
 * le refus d'un blocage donne son motif.
 */
final class DocumentEligibility
{
    /**
     * @throws BusinessRuleException contract_unavailable
     */
    public static function contract(Member $member): void
    {
        if ($member->portfolio !== Portfolio::Cga || $member->status !== MemberStatus::Active || $member->membership_number === null) {
            throw new BusinessRuleException('contract_unavailable');
        }
    }

    /**
     * @throws BusinessRuleException certificate_unavailable, attestation_blocked
     */
    public static function certificate(Member $member): void
    {
        if ($member->portfolio !== Portfolio::Cga || $member->status !== MemberStatus::Active || $member->membership_number === null) {
            throw new BusinessRuleException('certificate_unavailable', details: ['status' => $member->status->value, 'portfolio' => $member->portfolio->value]);
        }
        if ($member->attestation_blocked) {
            $block = AttestationBlock::query()->where('member_id', $member->id)->whereNull('unblocked_at')->first();

            throw new BusinessRuleException('attestation_blocked', details: ['reason' => $block?->reason, 'blocked_at' => $block?->blocked_at->toIso8601ZuluString()]);
        }
    }
}
