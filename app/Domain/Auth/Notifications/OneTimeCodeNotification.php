<?php

namespace App\Domain\Auth\Notifications;

use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Notifications\Contracts\SendsSms;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Code à usage unique par SMS ou par e-mail (F-AUTH-03, F-AUTH-05). Mis en file « notifications »
 * après la validation de la transaction ; le contenu de la tâche est chiffré : le code n'est jamais
 * en clair dans la table des files. Les codes de connexion sont hors quota SMS (02-TRD §10).
 */
class OneTimeCodeNotification extends Notification implements SendsSms, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const QUEUE = 'notifications';

    public function __construct(
        public readonly OtpPurpose $purpose,
        #[\SensitiveParameter] public readonly string $code,
        public readonly int $validityMinutes,
        public readonly string $channel,
    ) {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    public function toSms(object $notifiable): string
    {
        return __("auth.messages.{$this->purpose->value}.sms", $this->replacements());
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__("auth.messages.{$this->purpose->value}.mail_subject"))
            ->line(__("auth.messages.{$this->purpose->value}.mail_line", $this->replacements()))
            ->line(__("auth.messages.{$this->purpose->value}.mail_expiry", $this->replacements()));
    }

    /**
     * @return array{code: string, minutes: int}
     */
    private function replacements(): array
    {
        return ['code' => $this->code, 'minutes' => $this->validityMinutes];
    }
}
