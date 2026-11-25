<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Support\InvoiceCharges;
use App\Domain\Payments\Support\InvoicingMode;
use App\Domain\Users\Models\Membership;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * « Saisir la certification » d'une facture en mode manuel (RG-FNE-03, 02-TRD §8.5) : la facture a
 * été certifiée hors plateforme ; l'agent saisit le n° FNE (contrôlé par l'expression du paramétrage
 * « Facturation FNE » du centre, s'il en a une) et dépose la facture certifiée (PDF, contrôlé par son
 * contenu). La plateforme exige les deux avant de la marquer « Certifiée » ; le PDF déposé devient le
 * document remis au client.
 */
class RecordManualCertification
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly InvoicingMode $modes,
        private readonly InvoiceCharges $charges,
        private readonly DeliverCertifiedInvoice $deliver,
    ) {}

    /**
     * @param  UploadedFile  $pdf  facture certifiée, type réel contrôlé par la validation (PDF)
     *
     * @throws BusinessRuleException invoice_not_awaiting_manual_certification
     * @throws ValidationException n° FNE hors du format attendu
     */
    public function execute(Invoice $invoice, string $fneNumber, UploadedFile $pdf, Membership $requester): Invoice
    {
        return DB::transaction(function () use ($invoice, $fneNumber, $pdf, $requester): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::PendingCertification || $invoice->certification_mode !== CertificationMode::Manual) {
                throw new BusinessRuleException('invoice_not_awaiting_manual_certification');
            }
            $this->ensureNumberFormat($invoice->organization_id, $fneNumber);

            $file = $this->storage->storeUpload($pdf, 'invoices', $invoice->organization_id, $requester->user_id);
            $invoice->status = InvoiceStatus::Certified;
            $invoice->fne_number = $fneNumber;
            $invoice->certified_at = now()->toImmutable();
            $invoice->certified_file_id = $file->id;
            $invoice->rejection_reason = null;
            $invoice->save();
            $this->charges->certified($invoice);

            return $this->deliver->execute($invoice, $requester, $file);
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureNumberFormat(string $organizationId, string $fneNumber): void
    {
        $pattern = $this->modes->provider($organizationId)?->fne_number_pattern;
        if ($pattern === null || $pattern === '') {
            return;
        }

        try {
            $matches = preg_match('#'.str_replace('#', '\#', $pattern).'#u', $fneNumber) === 1;
        } catch (Throwable $exception) {
            Log::warning('Expression de contrôle du n° FNE illisible.', ['organization_id' => $organizationId, 'exception' => $exception->getMessage()]);
            $matches = true;
        }
        if (! $matches) {
            throw ValidationException::withMessages(['fne_number' => __('payments.fne_number_format')]);
        }
    }
}
