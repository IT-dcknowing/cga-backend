<?php

namespace App\Domain\Obligations\Support;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Http\Exceptions\BusinessRuleException;

/**
 * Montant payé et statut d'un paiement d'échéance (F-OBL-04, RG-OBL-07) : le payé est la somme des
 * paiements confirmés ; le statut s'en déduit — reste nul : payé ; payé entre zéro et le dû : payé en
 * partie ; rien de payé : à payer. Un paiement exige un montant dû connu et ne dépasse jamais le reste
 * dû (décision 0004).
 */
final class InstanceAmounts
{
    /**
     * Recalcule le payé et le statut d'un paiement ; sans effet sur une déclaration, une diligence ou une
     * échéance annulée.
     */
    public function refresh(ObligationInstance $instance): void
    {
        if ($instance->kind !== InstanceKind::Payment || $instance->status === InstanceStatus::Cancelled) {
            return;
        }

        $instance->amount_paid = (int) ObligationPayment::query()
            ->where('instance_id', $instance->id)
            ->where('status', PaymentStatus::Confirmed)
            ->sum('amount');
        $instance->status = self::status($instance->amount_due, $instance->amount_paid);
        $instance->save();
    }

    /**
     * Un paiement de ce montant peut-il compter sur cette échéance ?
     *
     * @throws BusinessRuleException instance_not_payment, instance_cancelled, obligation_amount_unknown,
     *                               obligation_payment_exceeds_balance
     */
    public function assertPayable(ObligationInstance $instance, int $amount): void
    {
        if ($instance->kind !== InstanceKind::Payment) {
            throw new BusinessRuleException('instance_not_payment');
        }
        if ($instance->status === InstanceStatus::Cancelled) {
            throw new BusinessRuleException('instance_cancelled');
        }
        $balance = $instance->balance();
        if ($balance === null) {
            throw new BusinessRuleException('obligation_amount_unknown');
        }
        if ($amount > $balance) {
            throw new BusinessRuleException('obligation_payment_exceeds_balance', __('errors.obligation_payment_exceeds_balance', ['balance' => number_format($balance, 0, ',', ' ')]), ['balance' => $balance]);
        }
    }

    public static function status(?int $due, int $paid): InstanceStatus
    {
        return match (true) {
            $due !== null && $paid >= $due => InstanceStatus::Paid,
            $paid > 0 => InstanceStatus::PartiallyPaid,
            default => InstanceStatus::ToPay,
        };
    }
}
