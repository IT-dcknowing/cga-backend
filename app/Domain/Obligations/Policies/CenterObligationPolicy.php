<?php

namespace App\Domain\Obligations\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Obligations propres du CGA (F-OBL-07, « Gestion interne ») : gérant et expert-comptable par défaut
 * (internal.manage, 01-PRD §3.3), dans l'espace de leur centre.
 */
class CenterObligationPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function manage(User $user): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Cga
            && $this->context->memberId() === null
            && $this->context->allows('internal.manage');
    }
}
