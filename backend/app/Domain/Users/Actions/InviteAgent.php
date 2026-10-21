<?php

namespace App\Domain\Users\Actions;

use App\Domain\Auth\Actions\IssueInvitation;
use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Notifications\AccessGrantedNotification;
use App\Domain\Auth\Notifications\InvitationNotification;
use App\Domain\Auth\Support\ActivationLink;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Data\AgentIdentity;
use App\Domain\Users\Data\InvitedAgent;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invitation d'un agent dans une organisation (F-ORG-09, F-ORG-10), un rattachement par rôle, ou d'un
 * accès en ligne d'un adhérent du CGA (F-ADH-09, rôles member_owner et member_user, rattachés au
 * dossier). Un compte unique par personne (RG-ACC-01) : le téléphone d'un compte existant lui ajoute
 * l'accès. Le premier accès passe toujours par le lien d'activation (RG-ACC-03), envoyé par SMS,
 * e-mail ou les deux ; un compte déjà actif reçoit seulement l'avis de son nouvel accès.
 */
class InviteAgent
{
    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly IssueInvitation $invitations,
        private readonly ActivationLink $links,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  non-empty-list<Role>  $roles
     * @param  Member|null  $member  dossier de l'adhérent, pour un accès en ligne
     *
     * @throws BusinessRuleException agent_already_member, member_access_exists, user_disabled
     * @throws ValidationException e-mail déjà pris, ou absent pour un envoi par e-mail
     */
    public function execute(Organization $organization, AgentIdentity $identity, array $roles, InvitationChannel $channel, User $inviter, ?Member $member = null): InvitedAgent
    {
        return DB::transaction(function () use ($organization, $identity, $roles, $channel, $inviter, $member): InvitedAgent {
            $phone = $this->phones->e164OrFail($identity->phone);
            $user = User::query()->where('phone', $phone)->lockForUpdate()->first();

            if ($user === null) {
                $user = $this->createUser($identity, $phone);
            } elseif (! in_array($user->status, [UserStatus::Invited, UserStatus::Active], true)) {
                throw new BusinessRuleException('user_disabled');
            } elseif ($member === null && $user->memberships()->where('organization_id', $organization->id)->whereNull('member_id')->exists()) {
                throw new BusinessRuleException('agent_already_member');
            } elseif ($member !== null && $user->memberships()->where('member_id', $member->id)->exists()) {
                throw new BusinessRuleException('member_access_exists');
            }

            if ($channel !== InvitationChannel::Sms && $user->email === null) {
                throw ValidationException::withMessages(['email' => __('errors.email_required')]);
            }

            $active = $user->status === UserStatus::Active;
            $memberships = array_map(fn (Role $role): Membership => $this->membership($user, $organization, $role, $active, $inviter, $member), $roles);

            if ($active) {
                $user->notify(new AccessGrantedNotification($organization->name, implode(', ', array_map(static fn (Role $role): string => $role->label(), $roles))));

                return new InvitedAgent($user, null, $channel);
            }

            $link = $this->links->for($this->invitations->execute($memberships[0], $channel));
            $user->notify(new InvitationNotification($link, $organization->name, (int) $this->settings->get('invitation.validity_days'), $channel));

            return new InvitedAgent($user, $link, $channel);
        });
    }

    /**
     * @throws ValidationException e-mail déjà pris par un autre compte
     */
    private function createUser(AgentIdentity $identity, string $phone): User
    {
        if ($identity->email !== null && User::query()->where('email', $identity->email)->exists()) {
            throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => __('validation.attributes.email')])]);
        }

        $user = new User(['first_name' => $identity->firstName, 'last_name' => $identity->lastName, 'phone' => $phone, 'email' => $identity->email]);
        $user->status = UserStatus::Invited;
        $user->save();

        return $user;
    }

    private function membership(User $user, Organization $organization, Role $role, bool $active, User $inviter, ?Member $member): Membership
    {
        $membership = new Membership;
        $membership->user_id = $user->id;
        $membership->organization_id = $organization->id;
        $membership->member_id = $member?->id;
        $membership->role = $role;
        $membership->status = $active ? MembershipStatus::Active : MembershipStatus::Invited;
        $membership->activated_at = $active ? now() : null;
        $membership->invited_by = $inviter->id;
        $membership->save();

        return $membership;
    }
}
