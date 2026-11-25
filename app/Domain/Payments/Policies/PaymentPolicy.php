<?php

namespace App\Domain\Payments\Policies;

use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * Honoraires et paiements (F-PAY-01 à 03) : consultés avec le dossier (members.view), saisis, confirmés
 * ou rejetés avec member_payments.manage (gérant, expert-comptable, comptables, chargé des honoraires) ;
 * déclarés par l'adhérent depuis son espace (member.payments.declare), pour son seul dossier ; le
 * barème lu par ceux qui inscrivent ou paramètrent, modifié par le gérant (cga.profile.manage).
 */
class PaymentPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user, Member $member): bool
    {
        return Gate::allows('view', $member);
    }

    public function viewCentre(User $user): bool
    {
        return $this->inCga() && $this->context->allows('member_payments.manage');
    }

    public function record(User $user, Member $member): bool
    {
        return Gate::allows('view', $member) && $this->context->allows('member_payments.manage');
    }

    public function review(User $user, MemberPayment $payment): bool
    {
        return $this->viewCentre($user) && $payment->organization_id === $this->context->organizationId();
    }

    public function declare(User $user): bool
    {
        return $this->context->has() && $this->context->memberId() !== null && $this->context->allows('member.payments.declare');
    }

    public function viewFeeSchedule(User $user): bool
    {
        return $this->inCga() && ($this->context->allows('cga.profile.view') || $this->context->allows('members.create'));
    }

    public function updateFeeSchedule(User $user): bool
    {
        return $this->inCga() && $this->context->allows('cga.profile.manage');
    }

    private function inCga(): bool
    {
        return $this->context->has() && $this->context->organizationType() === OrganizationType::Cga && $this->context->memberId() === null;
    }
}
