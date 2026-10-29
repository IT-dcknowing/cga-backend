<?php

namespace App\Domain\Obligations\Policies;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * Obligations d'un dossier (F-OBL-02, F-OBL-04) : lues par qui voit le dossier ; proposées, confirmées,
 * ajoutées ou retirées avec « Gérer les obligations », tant que le dossier n'est ni radié ni clos.
 */
class MemberObligationPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user, Member $member): bool
    {
        return Gate::forUser($user)->allows('view', $member);
    }

    public function manage(User $user, Member $member): bool
    {
        return $this->viewAny($user, $member)
            && $this->context->allows('obligations.manage')
            && ! in_array($member->status, [MemberStatus::Radiated, MemberStatus::Closed], true);
    }
}
