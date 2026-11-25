<?php

namespace App\Domain\Dues\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Cotisation CNCGA (PRD M14, matrice §3.3) : suivie par le CNCGA (dues.view), paiements saisis,
 * confirmés et rejetés (dues.payments.manage), relances (dues.remind) ; côté CGA, sa cotisation lue par
 * le gérant et l'expert-comptable (dues.own.view), paiement déclaré par le gérant (dues.own.declare).
 */
class NetworkDuePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewNetwork(User $user): bool
    {
        return $this->inNetwork() && $this->context->allows('dues.view');
    }

    public function manage(User $user): bool
    {
        return $this->inNetwork() && $this->context->allows('dues.payments.manage');
    }

    /** « Facturer » une cotisation (étape 6.9) : qui émet les factures au CNCGA. */
    public function invoice(User $user): bool
    {
        return $this->inNetwork() && $this->context->allows('invoices.issue');
    }

    public function remind(User $user): bool
    {
        return $this->inNetwork() && $this->context->allows('dues.remind');
    }

    public function viewOwn(User $user): bool
    {
        return $this->inCga() && $this->context->allows('dues.own.view');
    }

    public function declare(User $user): bool
    {
        return $this->inCga() && $this->context->allows('dues.own.declare');
    }

    private function inNetwork(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cncga;
    }

    private function inCga(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga && $this->context->memberId() === null;
    }
}
