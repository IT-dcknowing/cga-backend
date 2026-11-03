<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Models\MessagingProvider;
use App\Domain\Notifications\Support\MessagingProviders;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Interrupteur d'un compte d'envoi (RG-NOT-05) : un seul compte actif par canal — activer un compte
 * désactive les autres du canal ; sans compte actif, le canal est remplacé par la plateforme et l'e-mail.
 */
class ActivateMessagingProvider
{
    public function __construct(private readonly MessagingProviders $providers) {}

    public function execute(MessagingProvider $provider, bool $active, User $actor): MessagingProvider
    {
        return DB::transaction(function () use ($provider, $active, $actor): MessagingProvider {
            $provider = MessagingProvider::query()->lockForUpdate()->findOrFail($provider->id);
            if ($active) {
                MessagingProvider::query()->where('channel', $provider->channel)->whereKeyNot($provider->id)->where('is_active', true)->each(
                    static function (MessagingProvider $other) use ($actor): void {
                        $other->is_active = false;
                        $other->updated_by = $actor->id;
                        $other->save();
                    },
                );
            }
            $provider->is_active = $active;
            $provider->updated_by = $actor->id;
            $provider->save();
            $this->providers->forget();

            return $provider;
        });
    }
}
