<?php

namespace App\Domain\Concerns\Support;

use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Enums\MembershipStatus;
use App\Support\Tenancy\TenantContext;

/**
 * Accès de l'espace actif à une préoccupation (PRD M10, matrice §3.3) et son côté :
 * - l'adhérent, dans son espace (accès actif, pas en consultation seule), demandeur de ses demandes
 *   (member.concerns.write) ;
 * - les agents du CGA, qui traitent les demandes des adhérents (concerns.reply ; un dossier du portefeuille
 *   Cabinet demande en plus members.cabinet.view, RG-PTF-02) et demandeurs de celles du centre au CNCGA
 *   (concerns.network.write) ;
 * - le CNCGA, qui lit (network_concerns.view) et traite (network_concerns.handle) les demandes des CGA ;
 *   jamais celles des adhérents (RG-PRE-03).
 */
final class ConcernAccess
{
    public function __construct(private readonly TenantContext $context) {}

    /** Côté de l'espace actif dans cette demande, ou null s'il ne la voit pas. */
    public function side(Concern $concern): ?ConcernSide
    {
        if (! $this->context->has()) {
            return null;
        }

        return match (true) {
            $this->inMemberSpace() => $concern->level === ConcernLevel::MemberToCga
                && $concern->member_id === $this->context->memberId()
                && $this->context->allows('member.concerns.write') ? ConcernSide::Requester : null,
            $this->inCgaAgentSpace() => $this->agentSide($concern),
            $this->inNetwork() => $concern->level === ConcernLevel::CgaToCncga && $this->context->allows('network_concerns.view') ? ConcernSide::Handler : null,
            default => null,
        };
    }

    /** Répondre en traitant la demande, écrire une note interne, changer le statut. */
    public function handles(Concern $concern): bool
    {
        return $this->side($concern) === ConcernSide::Handler
            && ($concern->level === ConcernLevel::MemberToCga || $this->context->allows('network_concerns.handle'));
    }

    /** Transmettre au CNCGA une demande d'adhérent (F-PRE-03) : gérant, expert-comptable. */
    public function transfers(Concern $concern): bool
    {
        return $concern->level === ConcernLevel::MemberToCga && $this->handles($concern) && $this->context->allows('concerns.network.write');
    }

    public function inMemberSpace(): bool
    {
        return $this->context->has()
            && $this->context->memberId() !== null
            && $this->context->membership()->status !== MembershipStatus::ReadOnly;
    }

    public function inCgaAgentSpace(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga && $this->context->memberId() === null;
    }

    public function inNetwork(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cncga;
    }

    /** Le portefeuille Cabinet n'est vu qu'avec members.cabinet.view (RG-PTF-02). */
    public function seesCabinet(): bool
    {
        return $this->context->allows('members.cabinet.view');
    }

    private function agentSide(Concern $concern): ?ConcernSide
    {
        if ($concern->organization_id !== $this->context->organizationId()) {
            return null;
        }

        if ($concern->level === ConcernLevel::CgaToCncga) {
            return $this->context->allows('concerns.network.write') ? ConcernSide::Requester : null;
        }

        $cabinet = $concern->member?->portfolio === Portfolio::Cabinet;

        return $this->context->allows('concerns.reply') && (! $cabinet || $this->seesCabinet()) ? ConcernSide::Handler : null;
    }
}
