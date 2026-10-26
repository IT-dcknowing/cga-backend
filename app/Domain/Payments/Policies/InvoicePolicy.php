<?php

namespace App\Domain\Payments\Policies;

use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * Factures et avoirs du centre (F-PAY-05, F-PAY-06) : consultés, émis, certifiés (saisie en mode
 * manuel), soumis de nouveau, annulés ou corrigés par un avoir avec invoices.issue (gérant,
 * expert-comptable, comptables, chargé des cotisations et honoraires). L'adhérent ne voit que le PDF
 * d'une facture certifiée, parmi ses documents (RG-FNE-01, RG-DOC-04). Le cloisonnement garde chaque
 * facture dans son centre.
 */
class InvoicePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->issues();
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->issues() && $invoice->organization_id === $this->context->organizationId();
    }

    /**
     * Émission d'une facture pour un dossier du centre.
     */
    public function issue(User $user, Member $member): bool
    {
        return $this->issues() && Gate::allows('view', $member);
    }

    /**
     * Saisie de la certification, nouvelle soumission, annulation, avoir, remise du PDF (l'état de la
     * facture est contrôlé par chaque Action).
     */
    public function manage(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }

    private function issues(): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Cga
            && $this->context->memberId() === null
            && $this->context->allows('invoices.issue');
    }
}
