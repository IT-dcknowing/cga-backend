<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\CertificationAttemptStatus;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Jobs\CertifyInvoice;
use App\Domain\Payments\Models\FneCertification;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;

/**
 * Nouveaux essais de certification (RG-FNE-04, tâche fne:retry toutes les 15 minutes) : chaque facture
 * encore en attente dont le dernier essai a trouvé le service indisponible et dont l'heure du nouvel
 * essai est venue repart au service, dans l'espace de celui qui l'a émise. Tous les CGA (contournement
 * journalisé) ; idempotente : un Job unique par facture.
 */
class RetryFneCertifications
{
    /**
     * @return int nombre de factures soumises de nouveau
     */
    public function execute(): int
    {
        return Tenancy::bypass(function (): int {
            $due = FneCertification::query()
                ->where('status', CertificationAttemptStatus::Error)
                ->whereNotNull('next_retry_at')
                ->where('next_retry_at', '<=', now())
                ->whereIn('invoice_id', Invoice::query()
                    ->where('status', InvoiceStatus::PendingCertification)
                    ->where('certification_mode', '<>', CertificationMode::Manual)
                    ->select('id'))
                ->get()
                ->filter(fn (FneCertification $attempt): bool => $this->isLast($attempt));

            $invoices = Invoice::query()->whereKey($due->pluck('invoice_id'))->get();
            $retried = 0;
            foreach ($invoices as $invoice) {
                $membership = $this->issuer($invoice);
                if ($membership !== null) {
                    CertifyInvoice::dispatch($invoice->id, $membership->id);
                    $retried++;
                }
            }

            return $retried;
        }, 'Tâche fne:retry : nouveaux essais de certification FNE dans tous les CGA (RG-FNE-04).');
    }

    private function isLast(FneCertification $attempt): bool
    {
        return ! FneCertification::query()->where('invoice_id', $attempt->invoice_id)->where('attempt', '>', $attempt->attempt)->exists();
    }

    /**
     * Rattachement de l'auteur de la facture à son centre, de préférence encore actif.
     */
    private function issuer(Invoice $invoice): ?Membership
    {
        $memberships = Membership::query()->where('user_id', $invoice->created_by)->where('organization_id', $invoice->organization_id)->get();

        return $memberships->first(static fn (Membership $membership): bool => $membership->status === MembershipStatus::Active) ?? $memberships->first();
    }
}
