<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Mail\PlatformMailTransport;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Domain\Notifications\Providers\ConfiguredSmsProvider;
use App\Domain\Notifications\Providers\ConfiguredWhatsappProvider;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * « Message de test » d'un compte d'envoi (RG-NOT-05, 02-TRD §10), actif ou non : envoi réel vers le
 * numéro ou l'adresse saisis, réponse du fournisseur renvoyée et gardée sur le compte (date, résultat).
 * Un échec n'est pas une erreur de l'écran : il est rapporté et journalisé.
 */
class TestMessagingProvider
{
    public function __construct(
        private readonly ConfiguredSmsProvider $sms,
        private readonly ConfiguredWhatsappProvider $whatsapp,
    ) {}

    /**
     * @return array{ok: bool, provider_message_id: string|null, provider_response: string|null}
     */
    public function execute(MessagingProvider $provider, string $to, ?string $template): array
    {
        $text = (string) __('notifications.test_message');
        try {
            $id = match ($provider->channel) {
                'sms' => $this->sms->for($provider)->send($to, $text),
                'whatsapp' => $this->whatsapp->for($provider)->send($to, (string) ($template ?? array_key_first((array) ($provider->settings['templates'] ?? [])) ?? 'hello_world'), 'fr', []),
                default => $this->email($provider, $to, $text),
            };
            $result = ['ok' => true, 'provider_message_id' => $id, 'provider_response' => null];
        } catch (ExternalServiceException $exception) {
            Log::warning('Envois : message de test en échec.', ['provider' => $provider->id, 'service' => $exception->service]);
            $result = ['ok' => false, 'provider_message_id' => null, 'provider_response' => $exception->providerResponse ?? $exception->getMessage()];
        } catch (TransportExceptionInterface $exception) {
            Log::warning('Envois : message de test en échec.', ['provider' => $provider->id, 'error' => $exception->getMessage()]);
            $result = ['ok' => false, 'provider_message_id' => null, 'provider_response' => $exception->getMessage()];
        }

        DB::transaction(static function () use ($provider, $result): void {
            $provider->last_test_at = now();
            $provider->last_test_result = $result['ok'] ? 'ok' : mb_substr((string) $result['provider_response'], 0, 2000);
            $provider->save();
        });

        return $result;
    }

    private function email(MessagingProvider $provider, string $to, string $text): ?string
    {
        if ($provider->driver !== 'smtp') {
            Log::channel('sms')->info("E-mail de test à {$to} : {$text}");

            return null;
        }
        $message = (new Email)
            ->from(new Address((string) $provider->settings['from_address'], (string) $provider->settings['from_name']))
            ->to($to)
            ->subject((string) __('notifications.test_subject'))
            ->text($text);

        return PlatformMailTransport::smtp($provider)->send($message)?->getMessageId();
    }
}
