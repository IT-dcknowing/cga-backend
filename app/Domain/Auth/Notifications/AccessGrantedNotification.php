<?php

namespace App\Domain\Auth\Notifications;

use App\Domain\Notifications\Contracts\SendsSms;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Nouvel accès d'un compte déjà actif (RG-ACC-02) : la personne a déjà choisi son mot de passe, elle
 * retrouve l'espace dans son sélecteur dès sa prochaine connexion. Par SMS, et par e-mail s'il est
 * renseigné.
 */
class AccessGrantedNotification extends Notification implements SendsSms, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $roles,
    ) {
        $this->onQueue(OneTimeCodeNotification::QUEUE);
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->getAttribute('email') === null ? ['sms'] : ['sms', 'mail'];
    }

    public function toSms(object $notifiable): string
    {
        return __('auth.access_granted.sms', $this->replacements());
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.access_granted.mail_subject', $this->replacements()))
            ->line(__('auth.access_granted.mail_line', $this->replacements()));
    }

    /**
     * @return array{organization: string, roles: string}
     */
    private function replacements(): array
    {
        return ['organization' => $this->organizationName, 'roles' => $this->roles];
    }
}
