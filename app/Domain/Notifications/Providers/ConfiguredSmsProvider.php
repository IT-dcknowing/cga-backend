<?php

namespace App\Domain\Notifications\Providers;

use App\Domain\Notifications\Contracts\SmsProvider;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Domain\Notifications\Support\MessagingProviders;
use Illuminate\Contracts\Container\Container;

/**
 * Fournisseur SMS du compte actif de l'écran « Envois » (RG-NOT-05), résolu à chaque envoi : Orange CI,
 * agrégateur HTTP, ou journal (pilote « log », et tant qu'aucun compte n'est actif).
 */
class ConfiguredSmsProvider implements SmsProvider
{
    public function __construct(
        private readonly MessagingProviders $providers,
        private readonly Container $container,
    ) {}

    public function send(string $to, string $text): ?string
    {
        return $this->for($this->providers->active('sms'))->send($to, $text);
    }

    /** Vrai quand aucun SMS ne part réellement (pilote « log » ou aucun compte actif). */
    public function sendsNothing(): bool
    {
        return $this->for($this->providers->active('sms')) instanceof LogSmsProvider;
    }

    public function for(?MessagingProvider $account): SmsProvider
    {
        return match ($account?->driver) {
            'orange_ci' => new OrangeCiSmsProvider($account),
            'http_generic' => new HttpGenericSmsProvider($account),
            default => $this->container->make(LogSmsProvider::class),
        };
    }
}
