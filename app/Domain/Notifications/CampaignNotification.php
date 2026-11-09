<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Support\NotificationRouting;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Message d'un envoi groupé (F-COM-03) : objet et texte saisis par le centre, sur les canaux choisis
 * pour la campagne, dans la limite des règles d'envoi (type « campaign_message », non essentiel :
 * consentement SMS et WhatsApp exigé, RG-NOT-04 ; plage d'envoi, RG-NOT-02). Chaque envoi externe est
 * rattaché à sa campagne dans message_logs.
 */
final class CampaignNotification extends PlatformNotification
{
    private const CHANNELS = ['platform' => 'database', 'email' => 'mail', 'sms' => 'sms', 'whatsapp' => 'whatsapp'];

    /**
     * @param  list<string>  $channels  canaux de la campagne (platform, email, sms, whatsapp)
     */
    public function __construct(
        string $organizationId,
        ?string $membershipId,
        public string $campaignId,
        private string $title,
        private string $body,
        private array $channels,
    ) {
        parent::__construct($organizationId, $membershipId);
    }

    public function type(): string
    {
        return 'campaign_message';
    }

    public function replacements(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $wanted = array_map(static fn (string $channel): string => self::CHANNELS[$channel], $this->channels);
        $allowed = app(NotificationRouting::class)->channels($this, $notifiable);

        // Adhérent sans accès en ligne : ni plateforme ni consentement recueilli (RG-NOT-04) — e-mail seulement.
        if (! method_exists($notifiable, 'notifications')) {
            $allowed = ['mail'];
        }

        return array_values(array_intersect($wanted, $allowed));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->line($this->body);
    }

    public function toSms(object $notifiable): string
    {
        return $this->body;
    }

    protected function text(string $part): string
    {
        return $part === 'title' ? $this->title : $this->body;
    }
}
