<?php

namespace App\Support\Logging;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Compteurs d'alertes du jour (02-TRD §6.7, A09) : refus 403, échecs de connexion… lus par la
 * supervision sous la clé « alerts:{nom}:{date} ». Un compteur indisponible ne fait jamais échouer
 * la requête : l'incident est seulement signalé.
 */
class AlertCounter
{
    public const PREFIX = 'alerts:';

    public function __construct(
        private readonly Cache $cache,
        private readonly Repository $config,
    ) {}

    public function increment(string $alert): void
    {
        $key = self::PREFIX.$alert.':'.now()->toDateString();

        try {
            // Le cache sur la base n'incrémente pas une clé absente : elle est créée d'abord.
            $this->cache->add($key, 0, now()->addHours($this->config->integer('activity.alerts_ttl_hours')));
            $this->cache->increment($key);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
