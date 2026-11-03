<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Contracts\SendsSms;
use App\Domain\Notifications\Contracts\SendsWhatsapp;
use App\Domain\Notifications\Support\NotificationRouting;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification d'un événement de la plateforme (PRD M13), mise en file « notifications » : son type
 * (config/notifications.php) fixe ses canaux, ses textes (lang/fr/notifications.php, types.{type}) et
 * son modèle WhatsApp ; elle appartient à un centre (quota SMS) et, dans la plateforme, à l'espace du
 * destinataire (seules les notifications de l'espace actif s'affichent).
 */
abstract class PlatformNotification extends Notification implements SendsSms, SendsWhatsapp, ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $organizationId,
        public ?string $membershipId = null,
    ) {
        $this->onQueue((string) config('notifications.queue'));
        // Une notification émise dans une transaction ne part qu'une fois celle-ci validée.
        $this->afterCommit();
    }

    /** Clé du type dans config/notifications.php. */
    abstract public function type(): string;

    /**
     * Valeurs des textes de la notification.
     *
     * @return array<string, string|int>
     */
    abstract public function replacements(): array;

    /** Écran de la plateforme à ouvrir (chemin du SPA), s'il y en a un. */
    public function link(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return app(NotificationRouting::class)->channels($this, $notifiable);
    }

    /**
     * @return array<string, CarbonImmutable>
     */
    public function withDelay(object $notifiable): array
    {
        return app(NotificationRouting::class)->delays(CarbonImmutable::now());
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'organization_id' => $this->organizationId,
            'membership_id' => $this->membershipId,
            'title' => $this->text('title'),
            'body' => $this->text('body'),
            'link' => $this->link(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->text('title'))->line($this->text('body'));
        $link = $this->link();

        return $link === null ? $mail : $mail->action((string) __('notifications.open'), rtrim((string) config('app.frontend_url'), '/').$link);
    }

    public function toSms(object $notifiable): string
    {
        return $this->text('sms');
    }

    /**
     * @return array{template: string, parameters: list<string>}
     */
    public function toWhatsapp(object $notifiable): array
    {
        return [
            'template' => (string) (NotificationRouting::definition($this->type())['whatsapp_template'] ?? $this->type()),
            'parameters' => array_values(array_map('strval', $this->replacements())),
        ];
    }

    protected function text(string $part): string
    {
        return (string) __("notifications.types.{$this->type()}.{$part}", $this->replacements());
    }
}
