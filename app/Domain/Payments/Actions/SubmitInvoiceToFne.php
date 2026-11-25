<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Audit\Enums\ActivityResult;
use App\Domain\Payments\Enums\CertificationAttemptStatus;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Events\InvoiceCertificationFailed;
use App\Domain\Payments\Events\InvoiceRejected;
use App\Domain\Payments\Fne\FneCertifiers;
use App\Domain\Payments\Fne\FneResult;
use App\Domain\Payments\Models\FneCertification;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Support\InvoiceCharges;
use App\Domain\Payments\Support\InvoicingMode;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Http\Exceptions\ExternalServiceException;
use App\Support\Logging\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Soumission d'une facture en attente au service de certification de son mode (RG-FNE-02 à 04,
 * 02-TRD §8.5), appelée par le Job CertifyInvoice : chaque essai est enregistré (fne_certifications).
 * Acceptée, la facture est certifiée avec les éléments renvoyés, puis remise (DeliverCertifiedInvoice) ;
 * rejetée, elle porte le motif retourné ; service indisponible, elle reste en attente et un nouvel essai
 * est programmé (fne.retry.tries, fne.retry.backoff_seconds), le gérant étant alerté après le dernier.
 * L'appel au service se fait hors de toute transaction.
 */
class SubmitInvoiceToFne
{
    public function __construct(
        private readonly FneCertifiers $certifiers,
        private readonly InvoicingMode $modes,
        private readonly SettingsService $settings,
        private readonly DeliverCertifiedInvoice $deliver,
        private readonly InvoiceCharges $charges,
        private readonly ActivityLogger $activity,
    ) {}

    public function execute(Invoice $invoice, Membership $requester): Invoice
    {
        $invoice = Invoice::query()->with(['member', 'original'])->findOrFail($invoice->id);
        if ($invoice->status !== InvoiceStatus::PendingCertification || $invoice->certification_mode === CertificationMode::Manual) {
            return $invoice;
        }

        $attempt = $this->attempt($invoice);
        $certifier = $this->certifiers->for($invoice->certification_mode);
        $provider = $this->modes->provider($invoice->organization_id);

        try {
            if ($certifier === null || $provider === null) {
                throw new ExternalServiceException('fne', 'Aucun service de certification pour le mode '.$invoice->certification_mode->value.'.');
            }
            $result = $certifier->certify($invoice, $provider);
        } catch (ExternalServiceException $exception) {
            report($exception);

            return $this->unavailable($invoice, $attempt);
        }

        return $result->accepted ? $this->accepted($invoice, $attempt, $result, $requester) : $this->rejected($invoice, $attempt, $result);
    }

    private function attempt(Invoice $invoice): FneCertification
    {
        $attempt = new FneCertification;
        $attempt->organization_id = $invoice->organization_id;
        $attempt->invoice_id = $invoice->id;
        $attempt->attempt = (int) FneCertification::query()->where('invoice_id', $invoice->id)->max('attempt') + 1;
        $attempt->requested_at = now()->toImmutable();
        $attempt->status = CertificationAttemptStatus::Sent;
        $attempt->save();

        return $attempt;
    }

    /**
     * La certification est enregistrée pour elle-même : un PDF qui ne peut pas encore être produit ne
     * la remet jamais en cause (la facture se remet ensuite à la demande).
     */
    private function accepted(Invoice $invoice, FneCertification $attempt, FneResult $result, Membership $requester): Invoice
    {
        $invoice = DB::transaction(function () use ($invoice, $attempt, $result): Invoice {
            $this->answer($attempt, CertificationAttemptStatus::Accepted, $result->reference, null);

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $invoice->status = InvoiceStatus::Certified;
            $invoice->fne_number = $result->fneNumber;
            $invoice->fne_qr_payload = $result->qrPayload;
            $invoice->fne_stamp = $result->stamp;
            $invoice->certified_at = $result->certifiedAt;
            $invoice->rejection_reason = null;
            $invoice->save();
            $this->charges->certified($invoice);

            return $invoice;
        });

        try {
            return $this->deliver->execute($invoice, $requester);
        } catch (BusinessRuleException $exception) {
            $this->activity->record('invoices.delivery_deferred', ActivityResult::BusinessError, [
                'subject_type' => $invoice->getMorphClass(),
                'subject_id' => $invoice->id,
                'error_code' => $exception->errorCode,
            ]);

            return $invoice;
        }
    }

    private function rejected(Invoice $invoice, FneCertification $attempt, FneResult $result): Invoice
    {
        return DB::transaction(function () use ($invoice, $attempt, $result): Invoice {
            $this->answer($attempt, CertificationAttemptStatus::Rejected, $result->reference, $result->message);

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $invoice->status = InvoiceStatus::Rejected;
            $invoice->rejection_reason = $result->message;
            $invoice->save();

            InvoiceRejected::dispatch($invoice);

            return $invoice;
        });
    }

    /**
     * Service indisponible : nouvel essai programmé, ou alerte du gérant après le dernier (RG-FNE-04).
     */
    private function unavailable(Invoice $invoice, FneCertification $attempt): Invoice
    {
        $tries = (int) $this->settings->get('fne.retry.tries');
        $backoff = array_values(array_map('intval', (array) $this->settings->get('fne.retry.backoff_seconds')));
        $delay = $backoff[min($attempt->attempt, count($backoff)) - 1] ?? null;

        $attempt->status = CertificationAttemptStatus::Error;
        $attempt->responded_at = now()->toImmutable();
        $attempt->message = (string) __('payments.fne_unavailable');
        $attempt->next_retry_at = $attempt->attempt < $tries && $delay !== null ? now()->addSeconds($delay)->toImmutable() : null;
        $attempt->save();

        if ($attempt->next_retry_at === null) {
            InvoiceCertificationFailed::dispatch($invoice);
        }

        return $invoice;
    }

    private function answer(FneCertification $attempt, CertificationAttemptStatus $status, ?string $reference, ?string $message): void
    {
        $attempt->status = $status;
        $attempt->responded_at = now()->toImmutable();
        $attempt->provider_reference = $reference;
        $attempt->message = $message;
        $attempt->save();
    }
}
