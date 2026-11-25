<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Déclaration marquée « faite » (F-OBL-04, RG-OBL-05) : pour une obligation « déclaration puis
 * paiement » qui le prévoit, l'échéance de paiement de la même période naît aussitôt, avec le montant
 * déclaré (avant et après abattement pour la TEE) ; un montant nul la rend payée d'emblée (RG-OBL-07).
 * Rejouable : le paiement d'une période n'est jamais créé deux fois.
 */
class MarkDeclarationDone
{
    /**
     * @return ObligationInstance|null le paiement créé, s'il y en a un
     *
     * @throws BusinessRuleException instance_not_declaration, instance_already_done
     */
    public function execute(ObligationInstance $declaration, CarbonImmutable $doneOn, ?int $amountDue, ?int $amountGross = null): ?ObligationInstance
    {
        return DB::transaction(function () use ($declaration, $doneOn, $amountDue, $amountGross): ?ObligationInstance {
            $declaration = ObligationInstance::query()->with('type')->lockForUpdate()->findOrFail($declaration->id);
            if ($declaration->kind !== InstanceKind::Declaration) {
                throw new BusinessRuleException('instance_not_declaration');
            }
            if ($declaration->status !== InstanceStatus::Todo) {
                throw new BusinessRuleException('instance_already_done');
            }

            $declaration->status = InstanceStatus::Done;
            $declaration->done_at = $doneOn;
            $declaration->member_declared_status = null;
            $declaration->member_declared_at = null;
            $declaration->save();

            $type = $declaration->type;
            if ($type === null || $type->kind !== ObligationKind::DeclarationPayment || ! $type->creates_payment_on_done) {
                return null;
            }

            $payment = ObligationInstance::query()
                ->where('member_obligation_id', $declaration->member_obligation_id)
                ->where('kind', InstanceKind::Payment)
                ->where('period_label', $declaration->period_label)
                ->first();
            if ($payment !== null) {
                return $payment;
            }

            $payment = $declaration->replicate(['status', 'done_at', 'member_declared_status', 'member_declared_at', 'reminder_j7_sent_at', 'reminder_j1_sent_at', 'overdue_notified_at']);
            $payment->kind = InstanceKind::Payment;
            $payment->parent_instance_id = $declaration->id;
            $payment->amount_gross = $type->has_abatement ? ($amountGross ?? $amountDue) : null;
            $payment->amount_due = $amountDue;
            $payment->amount_paid = 0;
            $payment->status = $amountDue === 0 ? InstanceStatus::Paid : InstanceStatus::ToPay;
            $payment->save();

            return $payment;
        });
    }
}
