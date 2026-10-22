<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentRejected;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Rejet d'un paiement déclaré par l'adhérent (F-PAY-03), avec motif obligatoire ; l'adhérent en est
 * notifié (PaymentRejected). Rien n'est ventilé, aucun reçu n'est produit.
 */
class RejectPayment
{
    /**
     * @throws BusinessRuleException payment_not_pending
     */
    public function execute(MemberPayment $payment, string $reason, User $actor): MemberPayment
    {
        return DB::transaction(function () use ($payment, $reason, $actor): MemberPayment {
            $payment = MemberPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::PendingConfirmation) {
                throw new BusinessRuleException('payment_not_pending');
            }

            $payment->status = PaymentStatus::Rejected;
            $payment->rejection_reason = $reason;
            $payment->confirmed_by = $actor->id;
            $payment->confirmed_at = now()->toImmutable();
            $payment->save();

            PaymentRejected::dispatch($payment);

            return $payment->refresh();
        });
    }
}
