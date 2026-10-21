<?php

namespace App\Domain\Referentials\Policies;

use App\Domain\Referentials\ReferenceData\ReferenceDataset;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Données de référence (RG-DATA-01, 02) : le CNCGA et l'administrateur les remplacent et les valident,
 * avec le droit de gérer le registre pour la liste des CGA, celui de gérer les référentiels pour les
 * autres jeux.
 */
class ReferenceDataPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has() && ($this->context->allows('referentials.manage') || $this->context->allows('organizations.manage'));
    }

    public function manage(User $user, ReferenceDataset $dataset): bool
    {
        return $this->context->has() && $this->context->allows($dataset->permission());
    }
}
