<?php

namespace App\Domain\Trainings\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Formations (PRD M11, matrice §3.3) :
 * - organisateur : le CNCGA (trainings.network.manage, formations du réseau) ou un CGA (trainings.cga.manage,
 *   formations pour ses agents et adhérents) — création, publication, inscrits, paiements, présence,
 *   attestations, évaluations de ses propres formations ;
 * - un CGA (trainings.cga.manage) consulte les formations du réseau et y inscrit ses agents et adhérents ciblés,
 *   et désinscrit les personnes de son centre ;
 * - la personne ciblée s'inscrit elle-même : un accès adhérent actif (member.trainings.register) ou un agent
 *   d'un CGA ; elle se désinscrit et évalue la session.
 */
class TrainingPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function organize(User $user): bool
    {
        if (! $this->context->has() || $this->context->memberId() !== null) {
            return false;
        }

        return match ($this->context->organizationType()) {
            OrganizationType::Cncga => $this->context->allows('trainings.network.manage'),
            OrganizationType::Cga => $this->context->allows('trainings.cga.manage'),
            default => false,
        };
    }

    public function manage(User $user, Training $training): bool
    {
        return $this->organize($user) && $training->organization_id === $this->context->organizationId();
    }

    /** « Formations du réseau » vues d'un CGA, inscription de ses agents et adhérents. */
    public function browseNetwork(User $user): bool
    {
        return $this->inCgaAgentSpace() && $this->context->allows('trainings.cga.manage');
    }

    /** S'inscrire soi-même : accès adhérent actif, ou agent d'un CGA. */
    public function attend(User $user): bool
    {
        if (! $this->context->has()) {
            return false;
        }
        if ($this->context->memberId() !== null) {
            return $this->context->membership()->status !== MembershipStatus::ReadOnly && $this->context->allows('member.trainings.register');
        }

        return $this->inCgaAgentSpace() && $this->context->membership()->status === MembershipStatus::Active;
    }

    /** Sa propre inscription, dans l'espace où elle a été faite. */
    public function own(User $user, TrainingRegistration $registration): bool
    {
        return $this->attend($user) && $registration->membership_id === $this->context->membership()->id;
    }

    /** Se désinscrire, ou désinscrire une personne de son centre. */
    public function cancel(User $user, TrainingRegistration $registration): bool
    {
        return $this->own($user, $registration)
            || ($this->browseNetwork($user) && $registration->organization_id === $this->context->organizationId());
    }

    private function inCgaAgentSpace(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga && $this->context->memberId() === null;
    }
}
