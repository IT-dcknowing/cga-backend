<?php

namespace App\Domain\Users\Actions;

use App\Domain\Auth\Actions\IssueInvitation;
use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Notifications\InvitationNotification;
use App\Domain\Auth\Support\ActivationLink;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renvoi du lien d'activation à un agent qui n'a pas encore activé son accès (RG-ACC-03 : lien
 * valable 7 jours, renvoyable) ; le nouveau lien révoque le précédent.
 */
class ResendInvitation
{
    public function __construct(
        private readonly IssueInvitation $invitations,
        private readonly ActivationLink $links,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return string le lien envoyé
     *
     * @throws BusinessRuleException agent_not_invited
     * @throws ValidationException e-mail absent pour un envoi par e-mail
     */
    public function execute(Organization $organization, User $agent, InvitationChannel $channel): string
    {
        return DB::transaction(function () use ($organization, $agent, $channel): string {
            $membership = $agent->memberships()
                ->where('organization_id', $organization->id)
                ->whereNull('member_id')
                ->where('status', MembershipStatus::Invited)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if (! $membership instanceof Membership) {
                throw new BusinessRuleException('agent_not_invited');
            }
            if ($channel !== InvitationChannel::Sms && $agent->email === null) {
                throw ValidationException::withMessages(['email' => __('errors.email_required')]);
            }

            $link = $this->links->for($this->invitations->execute($membership, $channel));
            $agent->notify(new InvitationNotification($link, $organization->name, (int) $this->settings->get('invitation.validity_days'), $channel));

            return $link;
        });
    }
}
