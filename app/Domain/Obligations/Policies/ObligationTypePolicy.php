<?php

namespace App\Domain\Obligations\Policies;

use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Catalogue des obligations vu d'un CGA (F-OBL-01, RG-OBL-01) : lu par les agents qui voient les
 * dossiers ou gèrent les obligations ; obligations locales créées et modifiées avec « Gérer les
 * obligations », seulement celles du centre — jamais le catalogue national ni celles d'un autre CGA.
 * Le catalogue national se gère depuis le réseau.
 */
class ObligationTypePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->inCga() && ($this->context->allows('obligations.manage') || $this->context->allows('members.view'));
    }

    public function create(User $user): bool
    {
        return $this->inCga() && $this->context->allows('obligations.manage');
    }

    public function update(User $user, ObligationType $type): bool
    {
        return $this->create($user) && $type->organization_id === $this->context->organizationId();
    }

    /**
     * Catalogue national (F-OBL-01, RG-DATA-04) : versions, import d'une nouvelle version avec aperçu
     * de ses effets, validation ; CNCGA et administrateur de la plateforme (referentials.manage).
     */
    public function manageCatalog(User $user): bool
    {
        return $this->context->has()
            && in_array($this->context->organizationType(), [OrganizationType::Cncga, OrganizationType::Platform], true)
            && $this->context->allows('referentials.manage');
    }

    private function inCga(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga;
    }
}
