<?php

namespace App\Domain\Auth\Support;

use App\Domain\Auth\Data\IssuedCode;
use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Notifications\Contracts\SmsProvider;
use App\Domain\Notifications\Providers\LogSmsProvider;
use App\Domain\Users\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Recopie du code envoyé dans la réponse, pour essayer les parcours sans téléphone (collection
 * Postman, poste du développeur). Trois conditions, toutes requises : environnement « local »,
 * AUTH_ECHO_DEV_MESSAGES=true, et un pilote d'envoi qui n'envoie rien (SMS « log », e-mail « log »
 * ou « array »). Partout ailleurs, la réponse ne contient jamais le code.
 */
class DevMessages
{
    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly SmsProvider $sms,
    ) {}

    /**
     * @return array{dev_message?: array{channel: string, to: string, code: string}}
     */
    public function payload(?IssuedCode $issued): array
    {
        if ($issued === null || ! $this->enabled($issued->channel)) {
            return [];
        }

        return ['dev_message' => ['channel' => $issued->channel, 'to' => $issued->target, 'code' => $issued->code]];
    }

    /**
     * Lien d'activation d'une invitation (RG-ACC-03), aux mêmes conditions que les codes.
     *
     * @return array{dev_message?: array{channel: string, to: string, link: string}}
     */
    public function invitation(InvitationChannel $channel, User $user, #[\SensitiveParameter] ?string $link): array
    {
        $sms = $channel !== InvitationChannel::Email;
        if ($link === null || ! $this->enabled($sms ? 'sms' : 'mail')) {
            return [];
        }

        return ['dev_message' => ['channel' => $channel->value, 'to' => $sms ? $user->phone : (string) $user->email, 'link' => $link]];
    }

    private function enabled(string $channel): bool
    {
        if (! $this->app->isLocal() || ! $this->config->boolean('auth.echo_dev_messages')) {
            return false;
        }

        return $channel === 'sms'
            ? $this->sms instanceof LogSmsProvider
            : in_array($this->config->string('mail.default'), ['log', 'array'], true);
    }
}
