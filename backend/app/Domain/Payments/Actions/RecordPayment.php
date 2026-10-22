<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Members\Models\Member;
use App\Domain\Payments\Data\PaymentEntry;
use App\Domain\Payments\Enums\PaymentSource;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Enregistrer un paiement » par un agent du CGA (F-PAY-02) : nature et périodes choisies (sommes
 * dues), montant, date, mode, référence, justificatif facultatif. Le paiement est confirmé d'office :
 * ventilé, numéroté, son reçu produit.
 */
class RecordPayment
{
    public function __construct(
        private readonly ConfirmPayment $confirm,
        private readonly StorageService $storage,
    ) {}

    /**
     * @param  list<string>  $chargeIds  sommes dues réglées ; vide : les plus anciennes encore ouvertes
     *
     * @throws BusinessRuleException abbreviation_missing
     * @throws ValidationException somme due inconnue, montant supérieur au reste dû
     */
    public function execute(Member $member, PaymentEntry $entry, array $chargeIds, ?UploadedFile $proof, User $actor): MemberPayment
    {
        return DB::transaction(function () use ($member, $entry, $chargeIds, $proof, $actor): MemberPayment {
            $payment = new MemberPayment;
            $payment->organization_id = $member->organization_id;
            $payment->member_id = $member->id;
            $payment->amount = $entry->amount;
            $payment->paid_on = $entry->paidOn;
            $payment->method = $entry->method;
            $payment->reference = $entry->reference;
            $payment->proof_file_id = $proof === null ? null : $this->storage->storeUpload($proof, 'payments', $member->organization_id, $actor->id)->id;
            $payment->source = PaymentSource::CgaEntry;
            $payment->status = PaymentStatus::PendingConfirmation;
            $payment->recorded_by = $actor->id;
            $payment->save();

            return $this->confirm->execute($payment, $chargeIds, $actor);
        });
    }
}
