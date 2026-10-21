<?php

namespace App\Domain\Organizations\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Registre des CGA (PRD §3.3) : consultation (organizations.view), création et modification
 * (organizations.manage), abonnement (organizations.subscription.manage), suspension, réactivation et
 * retrait d'agrément par la Présidente seulement (organizations.suspend, verrouillé). Fiche du CGA de
 * l'espace actif : consultation (cga.profile.view), complément par le gérant (cga.profile.manage).
 */
class OrganizationPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->allows('organizations.view');
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->isCga($organization) && $this->allows('organizations.view');
    }

    public function create(User $user): bool
    {
        return $this->allows('organizations.manage');
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->isCga($organization) && $this->allows('organizations.manage');
    }

    public function changeSubscription(User $user, Organization $organization): bool
    {
        return $this->isCga($organization) && $this->allows('organizations.subscription.manage');
    }

    public function suspend(User $user, Organization $organization): bool
    {
        return $this->isCga($organization) && $this->allows('organizations.suspend');
    }

    /**
     * « Ouvrir l'accès » : invitation du gérant du CGA (F-ORG-02).
     */
    public function inviteManager(User $user, Organization $organization): bool
    {
        return $this->isCga($organization) && $this->allows('organizations.manage');
    }

    public function viewProfile(User $user, Organization $organization): bool
    {
        return $this->isOwnCga($organization) && $this->allows('cga.profile.view');
    }

    public function updateProfile(User $user, Organization $organization): bool
    {
        return $this->isOwnCga($organization) && $this->allows('cga.profile.manage');
    }

    private function allows(string $permission): bool
    {
        return $this->context->has() && $this->context->allows($permission);
    }

    private function isCga(Organization $organization): bool
    {
        return $organization->type === OrganizationType::Cga;
    }

    private function isOwnCga(Organization $organization): bool
    {
        return $this->isCga($organization) && $this->context->has() && $this->context->organizationId() === $organization->id;
    }
}
