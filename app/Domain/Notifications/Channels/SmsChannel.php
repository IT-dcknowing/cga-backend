<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Contracts\SendsSms;
use App\Domain\Notifications\Contracts\SmsProvider;
use App\Domain\Notifications\PlatformNotification;
use App\Domain\Notifications\Support\MessageLogger;
use App\Domain\Notifications\Support\SmsQuotas;
use App\Support\Http\Exceptions\ExternalServiceException;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * Canal « sms » des notifications Laravel (02-TRD §10) : le numéro vient de
 * routeNotificationFor('sms'), le texte de toSms(), l'envoi du fournisseur configuré. Une notification
 * d'un centre consomme son quota mensuel : au-delà, le SMS n'est pas envoyé (« Quota épuisé ») ; les
 * codes de connexion sont hors quota. Chaque envoi est écrit dans message_logs, sans le texte.
 */
class SmsChannel
{
    public function __construct(
        private readonly SmsProvider $provider,
        private readonly SmsQuotas $quotas,
        private readonly MessageLogger $logger,
    ) {}

    /**
     * @throws ExternalServiceException fournisseur indisponible (la notification sera retentée)
     */
    public function send(object $notifiable, Notification $notification): ?string
    {
        if (! $notification instanceof SendsSms) {
            throw new LogicException($notification::class.' ne prévoit pas d\'envoi par SMS.');
        }
        $to = method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('sms', $notification) : null;
        if (! is_string($to) || $to === '') {
            throw new LogicException('Aucun numéro de téléphone pour l\'envoi par SMS.');
        }

        if ($notification instanceof PlatformNotification && ! $this->quotas->consume($notification->organizationId, CarbonImmutable::now())) {
            $this->logger->record($notifiable, $notification, 'sms', $to, 'failed', error: (string) __('notifications.quota_exhausted'));

            return null;
        }

        try {
            $id = $this->provider->send($to, $notification->toSms($notifiable));
        } catch (ExternalServiceException $exception) {
            $this->logger->record($notifiable, $notification, 'sms', $to, 'failed', error: $exception->getMessage());

            throw $exception;
        }
        $this->logger->record($notifiable, $notification, 'sms', $to, 'sent', $id);

        return $id;
    }
}
