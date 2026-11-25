<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationPaymentSource;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Obligations\Support\InstanceAmounts;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * « Signaler comme fait / payé » par l'adhérent (F-OBL-05, RG-OBL-09) : la ligne passe « déclaré par
 * l'adhérent — à confirmer », visible des deux ; rien ne compte avant la confirmation du CGA, qui a le
 * dernier mot. Un paiement déclaré (montant, date, preuve facultative) attend en « à confirmer » ; il ne
 * dépasse pas le reste dû quand celui-ci est connu (décision 0004). Une déclaration à la fois par ligne.
 */
class DeclareInstance
{
    public function __construct(private readonly StorageService $storage) {}

    /**
     * @throws BusinessRuleException instance_cancelled, member_declaration_pending, instance_already_done,
     *                               instance_already_paid, instance_not_declarable, obligation_payment_exceeds_balance
     */
    public function execute(ObligationInstance $instance, ?int $amount, ?CarbonImmutable $paidOn, ?UploadedFile $proof, User $actor): ObligationInstance
    {
        return DB::transaction(function () use ($instance, $amount, $paidOn, $proof, $actor): ObligationInstance {
            $instance = ObligationInstance::query()->lockForUpdate()->findOrFail($instance->id);
            if ($instance->status === InstanceStatus::Cancelled) {
                throw new BusinessRuleException('instance_cancelled');
            }
            if ($instance->member_declared_status !== null) {
                throw new BusinessRuleException('member_declaration_pending');
            }

            $instance->member_declared_status = match ($instance->kind) {
                InstanceKind::Declaration => $this->declaration($instance),
                InstanceKind::Payment => $this->payment($instance, (int) $amount, $paidOn ?? CarbonImmutable::today(), $proof, $actor),
                InstanceKind::Diligence => throw new BusinessRuleException('instance_not_declarable'),
            };
            $instance->member_declared_at = CarbonImmutable::now();
            $instance->member_declaration_rejection = null;
            $instance->save();

            return $instance;
        });
    }

    private function declaration(ObligationInstance $instance): InstanceStatus
    {
        if ($instance->status !== InstanceStatus::Todo) {
            throw new BusinessRuleException('instance_already_done');
        }

        return InstanceStatus::Done;
    }

    private function payment(ObligationInstance $instance, int $amount, CarbonImmutable $paidOn, ?UploadedFile $proof, User $actor): InstanceStatus
    {
        if ($instance->status === InstanceStatus::Paid) {
            throw new BusinessRuleException('instance_already_paid');
        }
        $balance = $instance->balance();
        if ($balance !== null && $amount > $balance) {
            throw new BusinessRuleException('obligation_payment_exceeds_balance', __('errors.obligation_payment_exceeds_balance', ['balance' => number_format($balance, 0, ',', ' ')]), ['balance' => $balance]);
        }

        $payment = new ObligationPayment;
        $payment->organization_id = $instance->organization_id;
        $payment->instance_id = $instance->id;
        $payment->member_id = $instance->member_id;
        $payment->tax_nature = $instance->paymentTaxNature();
        $payment->amount = $amount;
        $payment->paid_on = $paidOn;
        $payment->source = ObligationPaymentSource::MemberDeclaration;
        $payment->status = PaymentStatus::PendingConfirmation;
        $payment->proof_file_id = $proof === null ? null : $this->storage->storeUpload($proof, 'obligations', $instance->organization_id, $actor->id)->id;
        $payment->recorded_by = $actor->id;
        $payment->save();

        return $instance->amount_due === null ? InstanceStatus::Paid : InstanceAmounts::status($instance->amount_due, $instance->amount_paid + $amount);
    }
}
