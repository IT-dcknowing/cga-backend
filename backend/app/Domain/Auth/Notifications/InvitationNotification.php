<?php

namespace App\Domain\Auth\Notifications;

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Notifications\Contracts\SendsSms;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Lien d'activation d'une invitation (RG-ACC-03, F-ORG-09), par SMS, par e-mail ou les deux. Mis en
 * file « notifications » après la validation de la transaction ; le contenu de la tâche est
 * chiffré : le lien n'est jamais en clair dans la table des files.
 */
class InvitationNotification extends Notification implements SendsSms, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] public readonly string $link,
        public readonly string $organizationName,
        public readonly int $validityDays,
        public readonly InvitationChannel $channel,
    ) {
        $this->onQueue(OneTimeCodeNotification::QUEUE);
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return match ($this->channel) {
            InvitationChannel::Sms => ['sms'],
            InvitationChannel::Email => ['mail'],
            InvitationChannel::SmsEmail => ['sms', 'mail'],
        };
    }

    public function toSms(object $notifiable): string
    {
        return __('auth.invitation.sms', $this->replacements());
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.invitation.mail_subject', $this->replacements()))
            ->line(__('auth.invitation.mail_line', $this->replacements()))
            ->action(__('auth.invitation.mail_action'), $this->link)
            ->line(__('auth.invitation.mail_expiry', $this->replacements()));
    }

    /**
     * @return array{organization: string, link: string, days: int}
     */
    private function replacements(): array
    {
        return ['organization' => $this->organizationName, 'link' => $this->link, 'days' => $this->validityDays];
    }
}
