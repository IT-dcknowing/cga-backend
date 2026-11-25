<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Models\MessagingProvider;
use App\Domain\Notifications\Support\MessagingProviders;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Compte d'envoi saisi dans l'écran « Envois » (RG-NOT-05, F-ADM-06) : canal, pilote, libellé,
 * paramètres ; identifiants chiffrés — un identifiant laissé vide en modification garde sa valeur. La
 * modification est journalisée sans aucun identifiant en clair ; le cache du compte actif est vidé.
 */
class SaveMessagingProvider
{
    public function __construct(private readonly MessagingProviders $providers) {}

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $secrets  identifiants saisis (les absents sont gardés)
     */
    public function execute(?MessagingProvider $provider, string $channel, string $driver, string $label, array $settings, array $secrets, User $actor): MessagingProvider
    {
        return DB::transaction(function () use ($provider, $channel, $driver, $label, $settings, $secrets, $actor): MessagingProvider {
            $provider ??= new MessagingProvider;
            $kept = $provider->exists && $provider->driver === $driver ? $provider->secrets : [];
            $provider->channel = $channel;
            $provider->driver = $driver;
            $provider->label = $label;
            $provider->settings = $settings;
            $provider->secrets = [...$kept, ...$secrets];
            $provider->updated_by = $actor->id;
            $provider->save();
            $this->providers->forget();

            return $provider;
        });
    }
}
