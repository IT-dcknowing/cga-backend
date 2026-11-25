<?php

namespace App\Domain\Exports\Policies;

use App\Domain\Exports\Enums\ExportType;
use App\Domain\Exports\Models\Export;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Exports Excel (F-EXP-01, RG-SEC-06) : un agent du CNCGA ou d'un CGA exporte avec exports.run une liste qu'il voit
 * (droit de la liste dans son espace) ; il ne suit et ne télécharge que ses propres exports.
 */
class ExportPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->has() && $this->context->memberId() === null && $this->context->allows('exports.run');
    }

    public function request(User $user, ExportType $type): bool
    {
        $permission = $this->viewAny($user) ? $type->permissionIn($this->context->organizationType()) : null;

        return $permission !== null && $this->context->allows($permission);
    }

    public function view(User $user, Export $export): bool
    {
        return $this->viewAny($user) && $export->membership_id === $this->context->membership()->id;
    }
}
