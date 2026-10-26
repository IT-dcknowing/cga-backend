<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\Enums\PreferenceChannel;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre les choix SMS / WhatsApp d'un utilisateur (RG-NOT-04) : accepter date le consentement,
 * refuser l'efface. Recueillis à l'activation (F-AUTH-02), modifiables dans le profil (F-AUTH-05).
 */
class SetNotificationPreferences
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<value-of<PreferenceChannel>, bool>  $accepted  canal => accepte les messages non essentiels
     */
    public function execute(User $user, array $accepted): void
    {
        DB::transaction(function () use ($user, $accepted): void {
            $current = NotificationPreference::query()->where('user_id', $user->id)->lockForUpdate()->get()->keyBy(
                static fn (NotificationPreference $preference): string => $preference->channel->value,
            );

            $changes = [];
            foreach ($accepted as $channel => $accepts) {
                $before = $current->get($channel);
                if ($before !== null && $before->accepts() === $accepts) {
                    continue;
                }

                NotificationPreference::query()->upsert([[
                    'user_id' => $user->id,
                    'channel' => PreferenceChannel::from($channel)->value,
                    'opted_out' => ! $accepts,
                    'consented_at' => $accepts ? now() : null,
                ]], ['user_id', 'channel'], ['opted_out', 'consented_at']);

                $changes[$channel] = [$before?->accepts(), $accepts];
            }

            if ($changes !== []) {
                $this->audit->record('notification_preferences_updated', $user, $changes);
            }
        });
    }
}
