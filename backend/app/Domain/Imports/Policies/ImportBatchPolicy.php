<?php

namespace App\Domain\Imports\Policies;

use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Imports (M17) : le registre des CGA est importé par le CNCGA et l'administrateur, avec le droit de
 * gérer le registre (organizations.manage, F-IMP-01) ; le portefeuille d'un CGA par son gérant
 * (members.import, F-IMP-02). Un lot n'est visible que de l'organisation qui l'a lancé (cloisonnement).
 */
class ImportBatchPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user, ImportType $type): bool
    {
        return $this->canImport($type);
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        return $this->canImport($batch->type);
    }

    public function create(User $user, ImportType $type): bool
    {
        return $this->canImport($type);
    }

    private function canImport(ImportType $type): bool
    {
        return match ($type) {
            ImportType::Cgas => $this->allows('organizations.manage'),
            ImportType::Members => $this->allows('members.import'),
            default => false,
        };
    }

    private function allows(string $permission): bool
    {
        return $this->context->has() && $this->context->allows($permission);
    }
}
