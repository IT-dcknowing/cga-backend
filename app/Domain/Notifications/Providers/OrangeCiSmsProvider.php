<?php

namespace App\Domain\Notifications\Providers;

use App\Domain\Notifications\Contracts\SmsProvider;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * API SMS d'Orange Côte d'Ivoire (02-TRD §10) : jeton OAuth2 (client_credentials) puis envoi depuis
 * l'adresse expéditeur du compte. Adresse de l'API, expéditeur et identifiants viennent du compte
 * d'envoi (écran « Envois »).
 */
class OrangeCiSmsProvider implements SmsProvider
{
    public function __construct(private readonly MessagingProvider $account) {}

    public function send(string $to, string $text): ?string
    {
        $base = rtrim((string) $this->account->settings['base_url'], '/');
        $sender = 'tel:'.$this->account->settings['sender_address'];
        try {
            $token = Http::timeout((int) config('messaging.http_timeout_seconds'))
                ->asForm()
                ->withBasicAuth((string) $this->account->secrets['client_id'], (string) $this->account->secrets['client_secret'])
                ->post($base.'/oauth/v3/token', ['grant_type' => 'client_credentials']);
            $this->ensure($token);

            $response = Http::timeout((int) config('messaging.http_timeout_seconds'))
                ->withToken((string) $token->json('access_token'))
                ->post($base.'/smsmessaging/v1/outbound/'.rawurlencode($sender).'/requests', ['outboundSMSMessageRequest' => array_filter([
                    'address' => 'tel:'.$to,
                    'senderAddress' => $sender,
                    'senderName' => $this->account->settings['sender_name'] ?? null,
                    'outboundSMSTextMessage' => ['message' => $text],
                ])]);
            $this->ensure($response);
        } catch (ConnectionException $exception) {
            throw new ExternalServiceException('sms:orange_ci', null, $exception);
        }

        $url = (string) $response->json('outboundSMSMessageRequest.resourceURL');

        return $url === '' ? null : basename($url);
    }

    /**
     * @throws ExternalServiceException réponse en erreur
     */
    private function ensure(Response $response): void
    {
        if ($response->failed()) {
            throw new ExternalServiceException('sms:orange_ci', $response->body());
        }
    }
}
