<?php

namespace App\Domain\Reports\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Fiche permanente du CGA (F-RPT-01, « Gestion interne ») : tenue par le gérant et l'expert-comptable
 * (internal.manage) ; lue aussi par qui prépare le rapport trimestriel (reports.prepare), puisque les
 * sections 1 à 3 en sont tirées. Dans l'espace de leur centre seulement.
 */
class PermanentProfilePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->inCga() && ($this->context->allows('internal.manage') || $this->context->allows('reports.prepare'));
    }

    public function manage(User $user): bool
    {
        return $this->inCga() && $this->context->allows('internal.manage');
    }

    private function inCga(): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Cga
            && $this->context->memberId() === null;
    }
}
