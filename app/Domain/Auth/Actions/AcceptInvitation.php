<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Models\Invitation;
use App\Domain\Notifications\Actions\SetNotificationPreferences;
use App\Domain\Notifications\Enums\PreferenceChannel;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Activation par invitation (F-AUTH-02, RG-ACC-03) : l'invité choisit son mot de passe et accepte
 * les conditions d'utilisation ; un adhérent donne en plus ses choix SMS / WhatsApp (RG-NOT-04,
 * consentement recueilli à l'activation). Le compte et ses rattachements invités dans l'organisation
 * deviennent actifs, le lien ne sert qu'une fois. Le canal qui a reçu le lien est tenu pour vérifié.
 * Lien expiré, déjà utilisé ou révoqué : 409 invitation_invalid, avec le contact de l'organisation qui
 * invite.
 */
class AcceptInvitation
{
    public function __construct(private readonly SetNotificationPreferences $preferences) {}

    /**
     * @throws BusinessRuleException invitation_invalid
     */
    public function execute(Invitation $invitation, #[\SensitiveParameter] string $password, bool $smsOptIn, bool $whatsappOptIn): Membership
    {
        return DB::transaction(function () use ($invitation, $password, $smsOptIn, $whatsappOptIn): Membership {
            $invitation = Invitation::query()
                ->with(['membership.organization', 'membership.user'])
                ->lockForUpdate()
                ->findOrFail($invitation->id);

            if (! $invitation->isUsable()) {
                throw new BusinessRuleException('invitation_invalid', details: ['organization_contact' => $invitation->organizationContact()]);
            }

            $membership = $invitation->membership;
            $user = $membership->user;

            $user->password = $password;
            $user->status = UserStatus::Active;
            if ($invitation->channel !== InvitationChannel::Email) {
                $user->phone_verified_at ??= now();
            }
            if ($invitation->channel !== InvitationChannel::Sms && $user->email !== null) {
                $user->email_verified_at ??= now();
            }
            $user->save();

            // Les rôles reçus avec la même invitation (même organisation, même adhérent) s'activent ensemble.
            Membership::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $membership->organization_id)
                ->where('member_id', $membership->member_id)
                ->where('status', MembershipStatus::Invited)
                ->whereKeyNot($membership->id)
                ->get()
                ->each(static function (Membership $sibling): void {
                    $sibling->status = MembershipStatus::Active;
                    $sibling->activated_at = now();
                    $sibling->save();
                });

            $membership->status = MembershipStatus::Active;
            $membership->activated_at = now();
            $membership->save();

            $invitation->accepted_at = now();
            $invitation->save();

            if ($membership->role->isMemberRole()) {
                $this->preferences->execute($user, [
                    PreferenceChannel::Sms->value => $smsOptIn,
                    PreferenceChannel::Whatsapp->value => $whatsappOptIn,
                ]);
            }

            return $membership;
        });
    }
}
