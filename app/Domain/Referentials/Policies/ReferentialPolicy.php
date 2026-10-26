<?php

namespace App\Domain\Referentials\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Référentiels nationaux (05-BACKEND-SCHEMA §4) : lisibles dans tout espace, gérés avec le droit
 * referentials.manage (Présidente, gestionnaire du CNCGA, administrateur de la plateforme, PRD §3.3).
 */
class ReferentialPolicy
{
    public const MANAGE = 'referentials.manage';

    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has();
    }

    public function view(User $user, Model $referential): bool
    {
        return $this->context->has();
    }

    public function create(User $user): bool
    {
        return $this->manages();
    }

    /**
     * « Proposer un secteur » (RG-REF-01) : un agent d'un CGA qui inscrit les adhérents.
     */
    public function propose(User $user): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Cga
            && $this->context->allows('members.create');
    }

    public function update(User $user, Model $referential): bool
    {
        return $this->manages();
    }

    public function delete(User $user, Model $referential): bool
    {
        return $this->manages();
    }

    private function manages(): bool
    {
        return $this->context->has() && $this->context->allows(self::MANAGE);
    }
}
