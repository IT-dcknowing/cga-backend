<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Members\Models\Member;
use App\Domain\Payments\Data\PaymentEntry;
use App\Domain\Payments\Enums\PaymentSource;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentDeclared;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * « Déclarer un paiement » par l'adhérent (F-PAY-03) : montant, date, mode, référence et preuve
 * obligatoire (photo ou PDF). Le paiement attend la confirmation du CGA ; il n'est ventilé et
 * numéroté qu'à ce moment.
 */
class DeclarePayment
{
    public function __construct(private readonly StorageService $storage) {}

    public function execute(Member $member, PaymentEntry $entry, UploadedFile $proof, User $actor): MemberPayment
    {
        return DB::transaction(function () use ($member, $entry, $proof, $actor): MemberPayment {
            $payment = new MemberPayment;
            $payment->organization_id = $member->organization_id;
            $payment->member_id = $member->id;
            $payment->amount = $entry->amount;
            $payment->paid_on = $entry->paidOn;
            $payment->method = $entry->method;
            $payment->reference = $entry->reference;
            $payment->proof_file_id = $this->storage->storeUpload($proof, 'payments', $member->organization_id, $actor->id)->id;
            $payment->source = PaymentSource::MemberDeclaration;
            $payment->status = PaymentStatus::PendingConfirmation;
            $payment->recorded_by = $actor->id;
            $payment->save();

            PaymentDeclared::dispatch($payment);

            return $payment->refresh();
        });
    }
}
