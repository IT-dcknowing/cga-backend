<?php

namespace App\Domain\Notifications\Support;

use App\Domain\Notifications\Models\MessagingProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Compte d'envoi actif d'un canal (RG-NOT-05), lu en base et mis en cache selon CACHE_STORE ; le cache
 * est vidé à chaque enregistrement d'un compte. Sans compte actif, le canal est remplacé par la
 * plateforme et l'e-mail.
 */
final class MessagingProviders
{
    private const CACHE_KEY = 'messaging_providers:active:';

    public function __construct(private readonly Repository $cache) {}

    public function active(string $channel): ?MessagingProvider
    {
        $id = $this->cache->rememberForever(self::CACHE_KEY.$channel, static fn (): string => (string) MessagingProvider::query()
            ->where('channel', $channel)
            ->where('is_active', true)
            ->value('id'));

        return $id === '' ? null : MessagingProvider::query()->find($id);
    }

    public function isActive(string $channel): bool
    {
        return $this->active($channel) !== null;
    }

    public function forget(): void
    {
        foreach (['sms', 'whatsapp', 'email'] as $channel) {
            $this->cache->forget(self::CACHE_KEY.$channel);
        }
    }
}
