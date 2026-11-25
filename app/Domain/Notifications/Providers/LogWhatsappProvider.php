<?php

namespace App\Domain\Notifications\Providers;

use App\Domain\Notifications\Contracts\WhatsappProvider;
use Illuminate\Log\LogManager;
use Illuminate\Support\Str;

/**
 * Pilote de développement et de recette (02-TRD §10) : aucun message WhatsApp ne part, chaque envoi est
 * écrit dans le journal « sms » (modèle et valeurs), qui tient lieu de boîte d'envoi.
 */
class LogWhatsappProvider implements WhatsappProvider
{
    public function __construct(private readonly LogManager $logs) {}

    public function send(string $to, string $template, string $language, array $parameters): ?string
    {
        $this->logs->channel('sms')->info("WhatsApp à {$to} : modèle {$template} ({$language}) ".implode(' | ', $parameters));

        return 'log-'.Str::lower((string) Str::ulid());
    }
}
