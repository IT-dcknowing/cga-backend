<?php

namespace App\Domain\Dues\Actions;

use App\Domain\Documents\Actions\IssueDocument;
use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Dues\Enums\DuesPaymentSource;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Dues\Models\NetworkDuePayment;
use App\Domain\Dues\Support\DuesRules;
use App\Domain\Notifications\Support\Recipients;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Numbering\NumberFormatter;
use App\Support\Numbering\SequenceService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Paiements de la cotisation (PRD M14) :
 * - F-COT-03 : déclaration du CGA (montant, date, mode, référence, preuve obligatoire), « à confirmer » ;
 * - F-COT-04 : saisie directe par le CNCGA, confirmée d'office ;
 * - confirmation par le CNCGA : reçu numéroté (numbering.dues_receipt.pattern) produit, réglé et statut
 *   recalculés (RG-COT-04), gérant averti ; rejet avec motif, gérant averti.
 * Un paiement ne dépasse jamais le reste dû. La cotisation reste distincte de l'abonnement (RG-COT-03).
 */
class DuesPayments
{
    private const SEQUENCE = 'dues_receipt';

    public function __construct(
        private readonly StorageService $storage,
        private readonly IssueDocument $documents,
        private readonly SequenceService $sequences,
        private readonly SettingsService $settings,
        private readonly Recipients $recipients,
    ) {}

    /**
     * @throws BusinessRuleException dues_already_paid, dues_payment_exceeds_balance
     */
    public function declare(NetworkDue $due, int $amount, CarbonImmutable $paidOn, PaymentMethod $method, ?string $reference, UploadedFile $proof, Membership $actor): NetworkDuePayment
    {
        return DB::transaction(function () use ($due, $amount, $paidOn, $method, $reference, $proof, $actor): NetworkDuePayment {
            $due = $this->lockedDue($due, $amount);
            $payment = $this->payment($due, $amount, $paidOn, $method, $reference, DuesPaymentSource::CgaDeclaration, $actor);
            $payment->proof_file_id = $this->storage->storeUpload($proof, 'dues', $due->organization_id, $actor->user_id)->id;
            $payment->status = PaymentStatus::PendingConfirmation;
            $payment->save();

            $this->recipients->notify($this->recipients->network('dues.payments.manage'), 'dues_payment_declared', [
                'cga' => $this->cgaName($due->organization_id), 'year' => $due->year, 'amount' => DocumentFormat::amount($amount),
            ], '/cncga/cotisations');

            return $payment;
        });
    }

    /**
     * @throws BusinessRuleException dues_already_paid, dues_payment_exceeds_balance
     */
    public function record(NetworkDue $due, int $amount, CarbonImmutable $paidOn, PaymentMethod $method, ?string $reference, Membership $actor): NetworkDuePayment
    {
        return DB::transaction(function () use ($due, $amount, $paidOn, $method, $reference, $actor): NetworkDuePayment {
            $due = $this->lockedDue($due, $amount);
            $payment = $this->payment($due, $amount, $paidOn, $method, $reference, DuesPaymentSource::CncgaEntry, $actor);
            $payment->status = PaymentStatus::PendingConfirmation;
            // Le paiement appartient au CGA débiteur, saisi depuis l'espace du CNCGA.
            Tenancy::bypass(static fn (): bool => $payment->save(), 'Cotisation : paiement saisi par le CNCGA.');

            return $this->confirm($payment, $actor);
        });
    }

    /**
     * @throws BusinessRuleException dues_payment_not_pending, dues_payment_exceeds_balance
     */
    public function confirm(NetworkDuePayment $payment, Membership $actor): NetworkDuePayment
    {
        return DB::transaction(function () use ($payment, $actor): NetworkDuePayment {
            $payment = $this->lockedPayment($payment);
            $due = $this->lockedDue($payment->due, $payment->amount);
            $now = CarbonImmutable::now();

            $payment->status = PaymentStatus::Confirmed;
            $payment->confirmed_by = $actor->user_id;
            $payment->confirmed_at = $now;
            $payment->receipt_number = NumberFormatter::format(
                (string) $this->settings->get('numbering.dues_receipt.pattern'),
                $this->sequences->next($actor->organization_id, self::SEQUENCE, $now->year),
                $now->year,
            );
            $payment->save();

            $due->amount_paid += $payment->amount;
            $due->status = DuesRules::status($due->amount_due, $due->amount_paid, CarbonImmutable::parse($due->deadline), $now->startOfDay());
            $due->save();

            $receipt = $this->documents->execute(new DocumentRequest(
                type: DocumentType::DuesReceipt,
                title: (string) __('dues.receipt_title', ['year' => $due->year]),
                number: $payment->receipt_number,
                fiscalYear: $due->year,
                visibleToMember: false,
                sourceType: 'network_due_payment',
                sourceId: $payment->id,
            ), $actor);
            $payment->receipt_document_id = $receipt->id;
            $payment->save();

            $this->reviewed($due, $payment, 'confirmed');

            return $payment;
        });
    }

    /**
     * @throws BusinessRuleException dues_payment_not_pending
     */
    public function reject(NetworkDuePayment $payment, string $reason, Membership $actor): NetworkDuePayment
    {
        return DB::transaction(function () use ($payment, $reason, $actor): NetworkDuePayment {
            $payment = $this->lockedPayment($payment);
            $payment->status = PaymentStatus::Rejected;
            $payment->rejection_reason = $reason;
            $payment->confirmed_by = $actor->user_id;
            $payment->confirmed_at = CarbonImmutable::now();
            $payment->save();
            $this->reviewed($payment->due, $payment, 'rejected');

            return $payment;
        });
    }

    /**
     * @throws BusinessRuleException dues_already_paid, dues_payment_exceeds_balance
     */
    private function lockedDue(NetworkDue $due, int $amount): NetworkDue
    {
        $due = Tenancy::bypass(static fn (): NetworkDue => NetworkDue::query()->lockForUpdate()->findOrFail($due->id), 'Cotisation : verrou du paiement.');
        if ($due->balance() === 0) {
            throw new BusinessRuleException('dues_already_paid');
        }
        if ($amount > $due->balance()) {
            throw new BusinessRuleException('dues_payment_exceeds_balance', __('errors.dues_payment_exceeds_balance', ['balance' => DocumentFormat::amount($due->balance())]), ['balance' => $due->balance()]);
        }

        return $due;
    }

    /**
     * @throws BusinessRuleException dues_payment_not_pending
     */
    private function lockedPayment(NetworkDuePayment $payment): NetworkDuePayment
    {
        $payment = Tenancy::bypass(static fn (): NetworkDuePayment => NetworkDuePayment::query()->with('due')->lockForUpdate()->findOrFail($payment->id), 'Cotisation : verrou du paiement.');
        if ($payment->status !== PaymentStatus::PendingConfirmation) {
            throw new BusinessRuleException('dues_payment_not_pending');
        }

        return $payment;
    }

    private function payment(NetworkDue $due, int $amount, CarbonImmutable $paidOn, PaymentMethod $method, ?string $reference, DuesPaymentSource $source, Membership $actor): NetworkDuePayment
    {
        $payment = new NetworkDuePayment;
        $payment->organization_id = $due->organization_id;
        $payment->network_due_id = $due->id;
        $payment->amount = $amount;
        $payment->paid_on = $paidOn;
        $payment->method = $method;
        $payment->reference = $reference;
        $payment->source = $source;
        $payment->created_by = $actor->user_id;

        return $payment;
    }

    private function reviewed(NetworkDue $due, NetworkDuePayment $payment, string $outcome): void
    {
        $this->recipients->notify($this->recipients->roles($due->organization_id, [Role::CgaManager]), 'dues_payment_reviewed', [
            'year' => $due->year, 'amount' => DocumentFormat::amount($payment->amount), 'outcome' => (string) __("notifications.outcomes.{$outcome}"),
        ], '/cga/cotisation');
    }

    private function cgaName(string $organizationId): string
    {
        return (string) Organization::query()->whereKey($organizationId)->value('name');
    }
}
