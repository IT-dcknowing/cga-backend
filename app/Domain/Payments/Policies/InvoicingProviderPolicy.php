<?php

namespace App\Domain\Payments\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Paramétrage « Facturation FNE » du centre (F-ADM-07, RG-FNE-03, PRD §3.3) : lu par le gérant et
 * l'expert-comptable (integrations.view), modifié et testé par le gérant (integrations.manage).
 */
class InvoicingProviderPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->inCga() && $this->context->allows('integrations.view');
    }

    public function manage(User $user): bool
    {
        return $this->inCga() && $this->context->allows('integrations.manage');
    }

    private function inCga(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga && $this->context->memberId() === null;
    }
}
