<?php

namespace App\Domain\Closing\Policies;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Campagne de clôture (F-OBL-08) : suivie par qui a closing.view (gérant, expert-comptable,
 * comptables, secrétaire), étapes marquées et dossiers attribués avec closing.manage ; l'adhérent voit
 * les étapes de son dossier dans son espace (member.closing.view), sauf radié (consultation seule de
 * ses documents, RG-ADH-12).
 */
class ClosingCampaignPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->inCga() && $this->context->allows('closing.view');
    }

    public function manage(User $user): bool
    {
        return $this->inCga() && $this->context->allows('closing.manage');
    }

    public function viewOwn(User $user): bool
    {
        return $this->context->has()
            && $this->context->memberId() !== null
            && $this->context->membership()->status !== MembershipStatus::ReadOnly
            && $this->context->allows('member.closing.view');
    }

    private function inCga(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga && $this->context->memberId() === null;
    }
}
