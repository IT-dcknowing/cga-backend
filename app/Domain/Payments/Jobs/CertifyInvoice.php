<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\Actions\SubmitInvoiceToFne;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Certification FNE d'une facture en attente (02-TRD §8.5, file « integrations »), dans l'espace de
 * celui qui l'a émise ou soumise de nouveau. Un seul essai par Job : les nouveaux essais d'un service
 * indisponible sont programmés par SubmitInvoiceToFne et relancés par fne:retry (RG-FNE-04). Une
 * facture n'est jamais soumise deux fois en même temps.
 */
class CertifyInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $membershipId,
    ) {
        $this->onQueue((string) config('queue-jobs.certify_invoice.queue'));
        $this->timeout = (int) config('queue-jobs.certify_invoice.timeout');
    }

    public function uniqueId(): string
    {
        return $this->invoiceId;
    }

    public function handle(TenantContext $context, SubmitInvoiceToFne $submit): void
    {
        $membership = Membership::query()->with('organization')->findOrFail($this->membershipId);

        $context->runAs($membership, fn (): Invoice => $submit->execute(Invoice::query()->findOrFail($this->invoiceId), $membership));
    }
}
