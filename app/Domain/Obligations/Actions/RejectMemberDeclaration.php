<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Obligations\Enums\ObligationPaymentSource;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rejet, par le CGA, d'une déclaration de l'adhérent (RG-OBL-09, décision 0004) : motif obligatoire,
 * paiement déclaré rejeté, la ligne revient « à faire » ou « à payer » et l'adhérent voit le motif.
 */
class RejectMemberDeclaration
{
    /**
     * @throws BusinessRuleException member_declaration_missing
     */
    public function execute(ObligationInstance $instance, string $reason, User $actor): ObligationInstance
    {
        return DB::transaction(function () use ($instance, $reason, $actor): ObligationInstance {
            $instance = ObligationInstance::query()->lockForUpdate()->findOrFail($instance->id);
            if ($instance->member_declared_status === null) {
                throw new BusinessRuleException('member_declaration_missing');
            }

            ObligationPayment::query()
                ->where('instance_id', $instance->id)
                ->where('source', ObligationPaymentSource::MemberDeclaration)
                ->where('status', PaymentStatus::PendingConfirmation)
                ->lockForUpdate()
                ->get()
                ->each(static function (ObligationPayment $payment) use ($actor): void {
                    $payment->status = PaymentStatus::Rejected;
                    $payment->confirmed_by = $actor->id;
                    $payment->confirmed_at = CarbonImmutable::now();
                    $payment->save();
                });

            $instance->member_declared_status = null;
            $instance->member_declared_at = null;
            $instance->member_declaration_rejection = $reason;
            $instance->save();

            return $instance;
        });
    }
}
