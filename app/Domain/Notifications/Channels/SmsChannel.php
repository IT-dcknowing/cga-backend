<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Contracts\SendsSms;
use App\Domain\Notifications\Contracts\SmsProvider;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * Canal « sms » des notifications Laravel (02-TRD §10) : le numéro vient de
 * routeNotificationFor('sms'), le texte de toSms(), l'envoi du fournisseur configuré. L'envoi est
 * journalisé par LogNotificationActivity, sans le texte du message.
 */
class SmsChannel
{
    public function __construct(private readonly SmsProvider $provider) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof SendsSms) {
            throw new LogicException($notification::class.' ne prévoit pas d\'envoi par SMS.');
        }

        $to = method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('sms', $notification) : null;
        if (! is_string($to) || $to === '') {
            throw new LogicException('Aucun numéro de téléphone pour l\'envoi par SMS.');
        }

        $this->provider->send($to, $notification->toSms($notifiable));
    }
}
