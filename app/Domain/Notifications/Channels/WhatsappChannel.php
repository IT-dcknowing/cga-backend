<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Contracts\SendsWhatsapp;
use App\Domain\Notifications\Contracts\WhatsappProvider;
use App\Domain\Notifications\Support\MessageLogger;
use App\Domain\Notifications\Support\MessagingProviders;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * Canal « whatsapp » des notifications (02-TRD §10) : modèle pré-approuvé de la notification, dont le
 * nom et la langue réels sont lus dans le compte d'envoi actif (settings.templates) ; numéro de
 * routeNotificationFor('whatsapp') ou, à défaut, du SMS. Chaque envoi est écrit dans message_logs.
 */
class WhatsappChannel
{
    public function __construct(
        private readonly WhatsappProvider $provider,
        private readonly MessagingProviders $providers,
        private readonly MessageLogger $logger,
    ) {}

    /**
     * @throws ExternalServiceException fournisseur indisponible (la notification sera retentée)
     */
    public function send(object $notifiable, Notification $notification): ?string
    {
        if (! $notification instanceof SendsWhatsapp) {
            throw new LogicException($notification::class.' ne prévoit pas d\'envoi par WhatsApp.');
        }
        $to = method_exists($notifiable, 'routeNotificationFor')
            ? ($notifiable->routeNotificationFor('whatsapp', $notification) ?? $notifiable->routeNotificationFor('sms', $notification))
            : null;
        if (! is_string($to) || $to === '') {
            throw new LogicException('Aucun numéro de téléphone pour l\'envoi par WhatsApp.');
        }

        $message = $notification->toWhatsapp($notifiable);
        $templates = (array) ($this->providers->active('whatsapp')?->settings['templates'] ?? []);
        $template = (array) ($templates[$message['template']] ?? []);

        try {
            $id = $this->provider->send($to, (string) ($template['name'] ?? $message['template']), (string) ($template['language'] ?? 'fr'), $message['parameters']);
        } catch (ExternalServiceException $exception) {
            $this->logger->record($notifiable, $notification, 'whatsapp', $to, 'failed', error: $exception->getMessage());

            throw $exception;
        }
        $this->logger->record($notifiable, $notification, 'whatsapp', $to, 'sent', $id);

        return $id;
    }
}
