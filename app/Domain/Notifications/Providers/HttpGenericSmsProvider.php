<?php

namespace App\Domain\Notifications\Providers;

use App\Domain\Notifications\Contracts\SmsProvider;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Agrégateur SMS configurable (02-TRD §10) : adresse, en-tête de la clé d'API, noms des champs du
 * destinataire, du texte et de l'expéditeur, chemin de l'identifiant dans la réponse JSON — tous saisis
 * dans le compte d'envoi.
 */
class HttpGenericSmsProvider implements SmsProvider
{
    public function __construct(private readonly MessagingProvider $account) {}

    public function send(string $to, string $text): ?string
    {
        $settings = $this->account->settings;
        try {
            $response = Http::timeout((int) config('messaging.http_timeout_seconds'))
                ->withHeaders([(string) $settings['key_header'] => (string) $this->account->secrets['api_key']])
                ->post((string) $settings['url'], [
                    (string) $settings['to_field'] => $to,
                    (string) $settings['text_field'] => $text,
                    (string) $settings['sender_field'] => (string) $settings['sender'],
                ]);
        } catch (ConnectionException $exception) {
            throw new ExternalServiceException('sms:http_generic', null, $exception);
        }
        if ($response->failed()) {
            throw new ExternalServiceException('sms:http_generic', $response->body());
        }
        $id = empty($settings['id_path']) ? null : $response->json((string) $settings['id_path']);

        return is_scalar($id) ? (string) $id : null;
    }
}
