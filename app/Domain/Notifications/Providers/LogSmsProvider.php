<?php

namespace App\Domain\Notifications\Providers;

use App\Domain\Notifications\Contracts\SmsProvider;
use Illuminate\Log\LogManager;

/**
 * Pilote de développement et de recette (02-TRD §10, §14) : aucun SMS ne part, chaque message est
 * écrit dans le journal « sms » (storage/logs/sms.log), qui tient lieu de boîte d'envoi.
 */
class LogSmsProvider implements SmsProvider
{
    public function __construct(private readonly LogManager $logs) {}

    public function send(string $to, string $text): void
    {
        $this->logs->channel('sms')->info("SMS à {$to} : {$text}");
    }
}
