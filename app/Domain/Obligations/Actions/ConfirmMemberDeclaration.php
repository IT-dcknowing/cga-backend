<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Obligations\Data\DeclarationReview;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\ObligationPaymentSource;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Obligations\Support\InstanceAmounts;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Confirmer la déclaration de l'adhérent » ou la « Corriger » (RG-OBL-09, décision 0004) : le CGA a
 * le dernier mot. Une déclaration faite est marquée faite à la date retenue, son paiement naît avec le
 * montant saisi (RG-OBL-05). Un paiement déclaré est confirmé avec le montant et la date retenus, après
 * saisie du montant dû s'il manquait, sans dépasser le reste dû ; il compte alors dans le payé
 * (RG-OBL-07).
 */
class ConfirmMemberDeclaration
{
    public function __construct(
        private readonly MarkDeclarationDone $done,
        private readonly InstanceAmounts $amounts,
    ) {}

    /**
     * @throws BusinessRuleException member_declaration_missing, obligation_amount_below_paid,
     *                               obligation_amount_unknown, obligation_payment_exceeds_balance
     */
    public function execute(ObligationInstance $instance, DeclarationReview $review, User $actor): ObligationInstance
    {
        return DB::transaction(function () use ($instance, $review, $actor): ObligationInstance {
            $instance = ObligationInstance::query()->lockForUpdate()->findOrFail($instance->id);
            if ($instance->member_declared_status === null || $instance->member_declared_at === null) {
                throw new BusinessRuleException('member_declaration_missing');
            }

            if ($instance->kind === InstanceKind::Declaration) {
                $doneOn = $review->doneOn ?? CarbonImmutable::parse($instance->member_declared_at)->startOfDay();
                $this->done->execute($instance, $doneOn, $review->amountDue, $review->amountGross);

                return $instance->refresh();
            }

            $payment = $this->pendingPayment($instance);
            if ($review->amountDue !== null) {
                if ($review->amountDue < $instance->amount_paid) {
                    throw new BusinessRuleException('obligation_amount_below_paid', __('errors.obligation_amount_below_paid', ['paid' => number_format($instance->amount_paid, 0, ',', ' ')]), ['amount_paid' => $instance->amount_paid]);
                }
                $instance->amount_due = $review->amountDue;
                $instance->amount_gross = $review->amountGross;
            }
            $amount = $review->amount ?? $payment->amount;
            $this->amounts->assertPayable($instance, $amount);

            $payment->amount = $amount;
            $payment->paid_on = $review->paidOn ?? $payment->paid_on;
            $payment->status = PaymentStatus::Confirmed;
            $payment->confirmed_by = $actor->id;
            $payment->confirmed_at = CarbonImmutable::now();
            $payment->save();

            $instance->member_declared_status = null;
            $instance->member_declared_at = null;
            $this->amounts->refresh($instance);

            return $instance;
        });
    }

    private function pendingPayment(ObligationInstance $instance): ObligationPayment
    {
        return ObligationPayment::query()
            ->where('instance_id', $instance->id)
            ->where('source', ObligationPaymentSource::MemberDeclaration)
            ->where('status', PaymentStatus::PendingConfirmation)
            ->lockForUpdate()
            ->first() ?? throw new BusinessRuleException('member_declaration_missing');
    }
}
