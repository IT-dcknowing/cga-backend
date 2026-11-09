<?php

namespace App\Domain\Statistics\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Tableaux de bord et statistiques (PRD M15, matrice §3.3) :
 * - le tableau de bord de l'espace actif est ouvert à tout agent du CNCGA (F-DSH-01) ou d'un CGA (F-DSH-02), et à
 *   l'adhérent dans son espace (F-DSH-03) ; chaque bloc y suit le droit de l'écran qu'il ouvre ;
 * - les statistiques nationales demandent statistics.national.view (F-STA-01) ;
 * - la position du CGA dans le réseau demande statistics.position.view (F-STA-02) ;
 * - « Actualiser » est ouvert à qui voit le tableau de bord d'un agent, limité par rate_limit.stats_refresh.
 */
class StatisticsPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewNetworkDashboard(User $user): bool
    {
        return $this->agentOf(OrganizationType::Cncga);
    }

    public function viewCgaDashboard(User $user): bool
    {
        return $this->agentOf(OrganizationType::Cga);
    }

    public function viewMemberDashboard(User $user): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Cga
            && $this->context->memberId() !== null;
    }

    public function viewNational(User $user): bool
    {
        return $this->agentOf(OrganizationType::Cncga) && $this->context->allows('statistics.national.view');
    }

    public function viewPosition(User $user): bool
    {
        return $this->agentOf(OrganizationType::Cga) && $this->context->allows('statistics.position.view');
    }

    /** « Exporter la plaquette PDF » (F-STA-03) : statistiques nationales et exports.run. */
    public function brochure(User $user): bool
    {
        return $this->viewNational($user) && $this->context->allows('exports.run');
    }

    public function refresh(User $user): bool
    {
        return $this->agentOf(OrganizationType::Cncga) || $this->agentOf(OrganizationType::Cga);
    }

    private function agentOf(OrganizationType $type): bool
    {
        return $this->context->has() && $this->context->memberId() === null && $this->context->organizationType() === $type;
    }
}
