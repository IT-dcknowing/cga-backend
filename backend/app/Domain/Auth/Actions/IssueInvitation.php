<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Models\Invitation;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Émet le lien d'activation d'un rattachement en attente (RG-ACC-03, 02-TRD §6.1) : jeton de
 * auth.invitation_token_bytes octets aléatoires, dont seule l'empreinte SHA-256 est gardée, valable
 * invitation.validity_days jours. Un nouveau lien révoque les précédents (renvoi). L'envoi par SMS
 * ou e-mail vient avec l'invitation des utilisateurs (étape 1.4).
 */
class IssueInvitation
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Repository $config,
    ) {}

    /**
     * @param  string|null  $token  jeton imposé (jeu de démonstration) ; tiré au hasard sinon
     * @return string le jeton en clair, à mettre dans le lien /activer/{jeton}
     */
    public function execute(Membership $membership, InvitationChannel $channel, #[\SensitiveParameter] ?string $token = null): string
    {
        if ($membership->status !== MembershipStatus::Invited) {
            throw new LogicException('Seul un rattachement en attente reçoit une invitation.');
        }

        $token ??= rtrim(strtr(base64_encode(random_bytes($this->config->integer('auth.invitation_token_bytes'))), '+/', '-_'), '=');

        DB::transaction(function () use ($membership, $channel, $token): void {
            $membership->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->lockForUpdate()->get()
                ->each(static function (Invitation $previous): void {
                    $previous->revoked_at = now();
                    $previous->save();
                });

            $invitation = new Invitation;
            $invitation->membership_id = $membership->id;
            $invitation->token_hash = Invitation::fingerprint($token);
            $invitation->channel = $channel;
            $invitation->sent_at = now();
            $invitation->expires_at = now()->addDays((int) $this->settings->get('invitation.validity_days'));
            $invitation->save();
        });

        return $token;
    }
}
