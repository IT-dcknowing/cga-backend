<?php

namespace App\Domain\Obligations\Policies;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * Obligations d'un dossier (F-OBL-02, F-OBL-04) : lues par qui voit le dossier ; proposées, confirmées,
 * ajoutées ou retirées, échéances saisies, déclarations de l'adhérent examinées avec « Gérer les
 * obligations », tant que le dossier n'est ni radié ni clos. Dans son espace, l'adhérent lit ses
 * obligations et signale une déclaration faite ou un paiement effectué (F-OBL-05, RG-OBL-09) ; radié,
 * il ne garde que ses documents (RG-ADH-12, 03-APPFLOW §5.2).
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

    public function viewOwn(User $user): bool
    {
        return $this->inMemberSpace() && $this->context->allows('member.obligations.view');
    }

    public function declare(User $user): bool
    {
        return $this->inMemberSpace() && $this->context->allows('member.obligations.declare');
    }

    /**
     * Planning des échéances du portefeuille (F-OBL-06) : agents du CGA qui voient les dossiers.
     */
    public function viewPlanning(User $user): bool
    {
        return $this->context->has()
            && $this->context->organizationType() === OrganizationType::Cga
            && $this->context->memberId() === null
            && $this->context->allows('members.view');
    }

    private function inMemberSpace(): bool
    {
        return $this->context->has()
            && $this->context->memberId() !== null
            && $this->context->membership()->status !== MembershipStatus::ReadOnly;
    }
}
