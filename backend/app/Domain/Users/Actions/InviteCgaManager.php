<?php

namespace App\Domain\Users\Actions;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Data\AgentIdentity;
use App\Domain\Users\Data\InvitedAgent;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Validation\ValidationException;

/**
 * « Ouvrir l'accès » d'un CGA par le CNCGA (F-ORG-02) : invitation de son gérant, qui reçoit le rôle
 * cga_manager. Un CGA n'a qu'un gérant invité ou actif ; inviter de nouveau le même gérant, encore en
 * attente, lui renvoie son lien. Son accès n'ouvre l'espace que si le CGA est abonné (RG-ORG-06).
 */
class InviteCgaManager
{
    public function __construct(
        private readonly InviteAgent $invite,
        private readonly ResendInvitation $resend,
        private readonly PhoneNormalizer $phones,
    ) {}

    /**
     * @throws BusinessRuleException cga_manager_exists, agent_already_member, user_disabled
     * @throws ValidationException
     */
    public function execute(Organization $cga, AgentIdentity $identity, InvitationChannel $channel, User $inviter): InvitedAgent
    {
        $managers = Membership::query()
            ->with('user')
            ->where('organization_id', $cga->id)
            ->where('role', Role::CgaManager)
            ->where('status', '!=', MembershipStatus::Disabled)
            ->get();

        if ($managers->isEmpty()) {
            return $this->invite->execute($cga, $identity, [Role::CgaManager], $channel, $inviter);
        }

        $phone = $this->phones->e164OrFail($identity->phone);
        $pending = $managers->first(fn (Membership $m): bool => $m->status === MembershipStatus::Invited && $m->user->phone === $phone);
        if ($managers->count() === 1 && $pending instanceof Membership) {
            return new InvitedAgent($pending->user, $this->resend->execute($cga, $pending->user, $channel), $channel);
        }

        throw new BusinessRuleException('cga_manager_exists');
    }
}
