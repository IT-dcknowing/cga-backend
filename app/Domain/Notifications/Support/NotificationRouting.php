<?php

namespace App\Domain\Notifications\Support;

use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\PlatformNotification;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Canaux et délais d'une notification de la plateforme (PRD M13, 02-TRD §10) :
 * - canaux prévus pour son type (config/notifications.php) ;
 * - RG-NOT-04 : SMS et WhatsApp d'un type non essentiel seulement avec le consentement du destinataire ;
 * - RG-NOT-05 : un canal sans compte d'envoi actif est remplacé par la plateforme et l'e-mail ;
 * - RG-NOT-03 : sans e-mail, un type contractuel part par SMS ;
 * - RG-NOT-02 : SMS et WhatsApp hors de la plage d'envoi sont différés à son ouverture.
 * La plateforme et l'e-mail ne sont jamais limités (RG-NOT-01).
 */
final class NotificationRouting
{
    private const EXTERNAL = ['sms', 'whatsapp'];

    public function __construct(
        private readonly MessagingProviders $providers,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{channels: list<string>, essential?: bool, contractual?: bool, whatsapp_template?: string}
     *
     * @throws LogicException type non déclaré
     */
    public static function definition(string $type): array
    {
        $definition = config("notifications.types.{$type}");
        if (! is_array($definition)) {
            throw new LogicException("Type de notification non déclaré dans config/notifications.php : {$type}.");
        }

        /** @var array{channels: list<string>, essential?: bool, contractual?: bool, whatsapp_template?: string} */
        return $definition;
    }

    /**
     * @return list<string>
     */
    public function channels(PlatformNotification $notification, object $notifiable): array
    {
        $definition = self::definition($notification->type());
        $essential = (bool) ($definition['essential'] ?? false);
        $email = $notifiable instanceof User ? $notifiable->email : null;
        $channels = [];
        $fallback = false;

        foreach ($definition['channels'] as $channel) {
            if ($channel === 'database') {
                $channels[] = 'database';
            } elseif ($channel === 'mail') {
                if (filled($email)) {
                    $channels[] = 'mail';
                } elseif ((bool) ($definition['contractual'] ?? false) && $this->providers->isActive('sms')) {
                    $channels[] = 'sms';
                }
            } elseif (in_array($channel, self::EXTERNAL, true)) {
                if (! $essential && ! $this->consents($notifiable, $channel)) {
                    continue;
                }
                if (! $this->providers->isActive($channel)) {
                    $fallback = true;

                    continue;
                }
                $channels[] = $channel;
            }
        }
        if ($fallback) {
            $channels[] = 'database';
            if (filled($email)) {
                $channels[] = 'mail';
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * Délais par canal : SMS et WhatsApp hors de la plage d'envoi partent à son ouverture suivante.
     *
     * @return array<string, CarbonImmutable>
     */
    public function delays(CarbonImmutable $now): array
    {
        /** @var array{from: string, to: string} $window */
        $window = (array) $this->settings->get('messaging.send_window');
        $opens = $now->setTimeFromTimeString($window['from']);
        $closes = $now->setTimeFromTimeString($window['to']);
        if ($now->greaterThanOrEqualTo($opens) && $now->lessThan($closes)) {
            return [];
        }
        $next = $now->lessThan($opens) ? $opens : $opens->addDay();

        return array_fill_keys(self::EXTERNAL, $next);
    }

    /**
     * Consentement du destinataire pour un canal (RG-NOT-04), recueilli à l'activation.
     */
    private function consents(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }
        $preference = NotificationPreference::query()->where('user_id', $notifiable->id)->where('channel', $channel)->first();

        return $preference?->accepts() ?? false;
    }
}
