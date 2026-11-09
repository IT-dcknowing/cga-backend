<?php

namespace App\Domain\Reports\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Rapport trimestriel (PRD M09, matrice §3.3) : préparé par le gérant, l'expert-comptable, les comptables
 * et la secrétaire (reports.prepare) ; transmis, accusé et corrigé par le gérant ou l'expert-comptable
 * (reports.transmit), dans l'espace de leur centre. Le CNCGA ne voit que l'état de transmission et le
 * tableau de synthèse (reports.network.view, RG-RPT-04).
 */
class QuarterlyReportPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->inCga() && ($this->context->allows('reports.prepare') || $this->context->allows('reports.transmit'));
    }

    public function prepare(User $user): bool
    {
        return $this->inCga() && $this->context->allows('reports.prepare');
    }

    public function transmit(User $user): bool
    {
        return $this->inCga() && $this->context->allows('reports.transmit');
    }

    public function viewNetwork(User $user): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Cncga
            && $this->context->allows('reports.network.view');
    }

    private function inCga(): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Cga
            && $this->context->memberId() === null;
    }
}
