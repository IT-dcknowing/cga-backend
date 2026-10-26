<?php

namespace App\Domain\Members\Policies;

use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Apporteurs d'affaires du centre (sous-portefeuille « Accessoires », RG-PTF-06) : consultés avec le
 * droit « Voir les apporteurs », gérés par ceux qui paramètrent le centre (cga.profile.manage) et les
 * voient. Le cloisonnement garde chaque apporteur dans son centre.
 */
class ReferrerPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga && $this->context->allows('members.referrers.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $this->context->allows('cga.profile.manage');
    }

    public function update(User $user, BusinessReferrer $referrer): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, BusinessReferrer $referrer): bool
    {
        return $this->create($user);
    }
}
