<?php

namespace App\Domain\Documents\Policies;

use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * Blocages manuels de l'attestation (RG-DOC-10) : historique visible avec le dossier (members.view) ;
 * blocage et déblocage par le gérant (attestations.block). Le cloisonnement garde chaque dossier dans
 * son centre.
 */
class AttestationBlockPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user, Member $member): bool
    {
        return Gate::allows('view', $member);
    }

    public function block(User $user, Member $member): bool
    {
        return Gate::allows('view', $member) && $this->context->allows('attestations.block');
    }
}
