<?php

namespace App\Domain\Notifications\Support;

use App\Domain\Notifications\CampaignNotification;
use App\Domain\Notifications\Models\MessageLog;
use App\Domain\Notifications\PlatformNotification;
use App\Domain\Users\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Écrit chaque envoi externe dans message_logs (02-TRD §10) : centre et type de la notification, canal,
 * destinataire, statut, identifiant et coût chez le fournisseur, erreur éventuelle — jamais le texte.
 */
final class MessageLogger
{
    public function record(object $notifiable, Notification $notification, string $channel, string $recipient, string $status, ?string $providerId = null, ?int $cost = null, ?string $error = null): MessageLog
    {
        $log = new MessageLog;
        $log->organization_id = $notification instanceof PlatformNotification ? $notification->organizationId : null;
        $log->notification_type = $notification instanceof PlatformNotification ? $notification->type() : class_basename($notification);
        $log->campaign_id = $notification instanceof CampaignNotification ? $notification->campaignId : null;
        $log->channel = $channel === 'mail' ? 'email' : $channel;
        $log->recipient = mb_substr($recipient, 0, 150);
        $log->user_id = $notifiable instanceof User ? $notifiable->id : null;
        $log->status = $status;
        $log->provider_message_id = $providerId === null ? null : mb_substr($providerId, 0, 100);
        $log->cost_units = $cost;
        $log->error = $error;
        $log->sent_at = $status === 'sent' ? now() : null;
        $log->save();

        return $log;
    }
}
