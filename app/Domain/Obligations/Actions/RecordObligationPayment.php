<?php

namespace App\Domain\Obligations\Actions;

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
 * « Enregistrer un paiement » d'une échéance par le CGA (F-OBL-04) : montant, date, preuve facultative ;
 * confirmé d'office, il compte aussitôt dans le payé et le statut (RG-OBL-07). Le montant dû doit être
 * connu et le paiement ne dépasse jamais le reste dû (décision 0004).
 */
class RecordObligationPayment
{
    public function __construct(
        private readonly InstanceAmounts $amounts,
        private readonly StorageService $storage,
    ) {}

    /**
     * @throws BusinessRuleException instance_not_payment, instance_cancelled, obligation_amount_unknown,
     *                               obligation_payment_exceeds_balance
     */
    public function execute(ObligationInstance $instance, int $amount, CarbonImmutable $paidOn, ?UploadedFile $proof, User $actor): ObligationPayment
    {
        return DB::transaction(function () use ($instance, $amount, $paidOn, $proof, $actor): ObligationPayment {
            $instance = ObligationInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->amounts->assertPayable($instance, $amount);

            $payment = new ObligationPayment;
            $payment->organization_id = $instance->organization_id;
            $payment->instance_id = $instance->id;
            $payment->member_id = $instance->member_id;
            $payment->tax_nature = $instance->paymentTaxNature();
            $payment->amount = $amount;
            $payment->paid_on = $paidOn;
            $payment->source = ObligationPaymentSource::CgaEntry;
            $payment->status = PaymentStatus::Confirmed;
            $payment->proof_file_id = $proof === null ? null : $this->storage->storeUpload($proof, 'obligations', $instance->organization_id, $actor->id)->id;
            $payment->confirmed_by = $actor->id;
            $payment->confirmed_at = CarbonImmutable::now();
            $payment->recorded_by = $actor->id;
            $payment->save();

            $this->amounts->refresh($instance);

            return $payment;
        });
    }
}
