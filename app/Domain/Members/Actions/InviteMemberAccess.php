<?php

namespace App\Domain\Members\Actions;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Actions\InviteAgent;
use App\Domain\Users\Data\AgentIdentity;
use App\Domain\Users\Data\InvitedAgent;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Validation\ValidationException;

/**
 * « Inviter » un accès en ligne de l'adhérent (F-ADH-09) : l'accès principal (le dirigeant, un seul
 * par dossier) ou un accès secondaire, après la validation de l'adhésion (RG-ADH-06). L'invitation
 * elle-même est celle des agents : compte unique par téléphone, lien d'activation (RG-ACC-01, 03).
 */
class InviteMemberAccess
{
    public function __construct(private readonly InviteAgent $invite) {}

    /**
     * @throws BusinessRuleException member_draft, member_closed, member_owner_exists, member_access_exists, user_disabled
     * @throws ValidationException e-mail déjà pris, ou absent pour un envoi par e-mail
     */
    public function execute(Member $member, AgentIdentity $identity, Role $role, InvitationChannel $channel, User $inviter): InvitedAgent
    {
        if ($member->status === MemberStatus::Draft) {
            throw new BusinessRuleException('member_draft');
        }
        if (! in_array($member->status, [MemberStatus::Active, MemberStatus::RadiationPending], true)) {
            throw new BusinessRuleException('member_closed');
        }
        $ownerExists = Membership::query()
            ->where('member_id', $member->id)
            ->where('role', Role::MemberOwner)
            ->where('status', '!=', MembershipStatus::Disabled)
            ->exists();
        if ($role === Role::MemberOwner && $ownerExists) {
            throw new BusinessRuleException('member_owner_exists');
        }

        return $this->invite->execute(Organization::query()->findOrFail($member->organization_id), $identity, [$role], $channel, $inviter, $member);
    }
}
