<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Audit\Enums\ActivityResult;
use App\Domain\Notifications\Support\MessageLogger;
use App\Support\Logging\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Chaque envoi (SMS, e-mail, WhatsApp) au journal d'activité (02-TRD §6.5) : canal, type de
 * notification, destinataire. Jamais le texte du message, qui peut contenir un code. Inscrit au
 * démarrage : le journal (lié à la requête ou au Job en cours) est résolu à chaque envoi.
 */
class LogNotificationActivity
{
    public function sent(NotificationSent $event): void
    {
        $this->record(ActivityResult::Success, $event->notifiable, $event->notification, $event->channel);

        // Un e-mail parti va aussi au journal des envois (02-TRD §10) ; SMS et WhatsApp y sont écrits
        // par leur canal, avec l'identifiant du fournisseur.
        if ($event->channel === 'mail') {
            $to = $event->notifiable instanceof AnonymousNotifiable
                ? ($event->notifiable->routes['mail'] ?? null)
                : ($event->notifiable instanceof Model ? $event->notifiable->getAttribute('email') : null);
            if (is_string($to)) {
                app(MessageLogger::class)->record($event->notifiable, $event->notification, 'mail', $to, 'sent');
            }
        }
    }

    public function failed(NotificationFailed $event): void
    {
        $this->record(ActivityResult::Error, $event->notifiable, $event->notification, $event->channel);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            NotificationSent::class => 'sent',
            NotificationFailed::class => 'failed',
        ];
    }

    private function record(ActivityResult $result, mixed $notifiable, object $notification, string $channel): void
    {
        app(ActivityLogger::class)->record('notification.'.$channel, $result, [
            'actor_type' => 'system',
            'actor_label' => 'system:notifications',
            'subject_type' => $notifiable instanceof Model ? $notifiable->getMorphClass() : null,
            'subject_id' => $notifiable instanceof Model ? (string) $notifiable->getKey() : null,
            'params' => [
                'notification' => class_basename($notification),
                'to' => $notifiable instanceof AnonymousNotifiable ? ($notifiable->routes[$channel] ?? null) : null,
            ],
        ]);
    }
}
