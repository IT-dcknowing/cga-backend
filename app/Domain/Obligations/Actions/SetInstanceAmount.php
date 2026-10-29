<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Support\InstanceAmounts;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * « Saisir le montant dû » d'un paiement (F-OBL-04, RG-OBL-08) : le CGA est le référent des montants ;
 * pour la TEE, montant avant et après abattement, tous deux saisis par le CGA. Le statut se recalcule
 * sur les paiements confirmés (RG-OBL-07) ; le dû ne descend jamais sous le déjà payé (décision 0004).
 */
class SetInstanceAmount
{
    public function __construct(private readonly InstanceAmounts $amounts) {}

    /**
     * @throws BusinessRuleException instance_not_payment, instance_cancelled, obligation_amount_below_paid
     */
    public function execute(ObligationInstance $instance, int $amountDue, ?int $amountGross): ObligationInstance
    {
        return DB::transaction(function () use ($instance, $amountDue, $amountGross): ObligationInstance {
            $instance = ObligationInstance::query()->lockForUpdate()->findOrFail($instance->id);
            if ($instance->kind !== InstanceKind::Payment) {
                throw new BusinessRuleException('instance_not_payment');
            }
            if ($instance->status === InstanceStatus::Cancelled) {
                throw new BusinessRuleException('instance_cancelled');
            }
            if ($amountDue < $instance->amount_paid) {
                throw new BusinessRuleException('obligation_amount_below_paid', __('errors.obligation_amount_below_paid', ['paid' => number_format($instance->amount_paid, 0, ',', ' ')]), ['amount_paid' => $instance->amount_paid]);
            }

            $instance->amount_due = $amountDue;
            $instance->amount_gross = $amountGross;
            $this->amounts->refresh($instance);

            return $instance;
        });
    }
}
