<?php

namespace App\Domain\Notifications\Providers;

use App\Domain\Notifications\Contracts\WhatsappProvider;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business Cloud API (02-TRD §10) : message par modèle pré-approuvé, depuis le numéro du compte
 * d'envoi, avec son jeton d'accès.
 */
class WhatsappCloudProvider implements WhatsappProvider
{
    public function __construct(private readonly MessagingProvider $account) {}

    public function send(string $to, string $template, string $language, array $parameters): ?string
    {
        $url = rtrim((string) $this->account->settings['base_url'], '/').'/'.$this->account->settings['phone_number_id'].'/messages';
        try {
            $response = Http::timeout((int) config('messaging.http_timeout_seconds'))
                ->withToken((string) $this->account->secrets['access_token'])
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($to, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $template,
                        'language' => ['code' => $language],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $parameters),
                        ]],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new ExternalServiceException('whatsapp:whatsapp_cloud', null, $exception);
        }
        if ($response->failed()) {
            throw new ExternalServiceException('whatsapp:whatsapp_cloud', $response->body());
        }
        $id = $response->json('messages.0.id');

        return is_string($id) ? $id : null;
    }
}
