<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;

/**
 * Somme due « droit d'adhésion » d'un dossier du portefeuille CGA (F-PAY-02, RG-ADH-02), tenue à jour
 * avec la fiche : ouverte à l'inscription pour le montant contractuel (RG-PAY-01), ajustée tant que
 * rien n'est payé, annulée si le dossier quitte le portefeuille CGA avant paiement ou si le brouillon
 * est supprimé. Un dossier repris d'un portefeuille existant est réputé l'avoir acquitté (RG-IMP-03).
 * Appelée dans la transaction de l'Action du dossier.
 */
class SyncEntryFee
{
    /**
     * @param  bool  $settled  droit d'adhésion déjà acquitté hors plateforme (import, RG-IMP-03)
     */
    public function execute(Member $member, bool $settled = false): ?MemberCharge
    {
        $charge = MemberCharge::query()
            ->where('member_id', $member->id)
            ->where('kind', ChargeKind::EntryFee)
            ->where('status', '!=', ChargeStatus::Cancelled)
            ->lockForUpdate()
            ->first();

        $wanted = $member->portfolio === Portfolio::Cga && ! $member->trashed()
            && in_array($member->status, [MemberStatus::Draft, MemberStatus::Active, MemberStatus::RadiationPending], true)
            && $member->entry_fee_amount > 0;

        if ($charge !== null && $charge->amount_paid === 0) {
            if ($wanted && $settled) {
                $charge->amount = $member->entry_fee_amount;
                $charge->amount_paid = $member->entry_fee_amount;
                $charge->status = ChargeStatus::Paid;
                $charge->save();

                return $charge;
            }
            if (! $wanted) {
                $charge->status = ChargeStatus::Cancelled;
                $charge->save();

                return null;
            }
            if ($charge->amount !== $member->entry_fee_amount) {
                $charge->amount = $member->entry_fee_amount;
                $charge->save();
            }
        }
        if ($charge !== null || ! $wanted) {
            return $charge;
        }

        $charge = new MemberCharge;
        $charge->organization_id = $member->organization_id;
        $charge->member_id = $member->id;
        $charge->kind = ChargeKind::EntryFee;
        $charge->due_date = today();
        $charge->amount = $member->entry_fee_amount;
        $charge->amount_paid = $settled ? $member->entry_fee_amount : 0;
        $charge->status = $settled ? ChargeStatus::Paid : ChargeStatus::Due;
        $charge->save();

        return $charge;
    }
}
