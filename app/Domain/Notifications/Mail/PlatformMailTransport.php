<?php

namespace App\Domain\Notifications\Mail;

use App\Domain\Notifications\Models\MessagingProvider;
use App\Domain\Notifications\Support\MessagingProviders;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Transport « platform » des e-mails (RG-NOT-05, 02-TRD §10) : à chaque envoi, le compte e-mail actif de
 * l'écran « Envois » — serveur SMTP, identifiants, expéditeur — ou, sans compte actif ou avec le pilote
 * « log », le mailer de repli de la configuration (MAIL_FALLBACK_MAILER). Aucun identifiant dans .env.
 */
final class PlatformMailTransport implements TransportInterface
{
    public function __construct(
        private readonly MessagingProviders $providers,
        private readonly MailManager $mail,
        private readonly string $fallback,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $account = $this->providers->active('email');
        if ($account === null || $account->driver !== 'smtp') {
            return $this->mail->mailer($this->fallback)->getSymfonyTransport()->send($message, $envelope);
        }
        if ($message instanceof Message) {
            $message->getHeaders()->remove('From');
            $message->getHeaders()->addMailboxListHeader('From', [new Address((string) $account->settings['from_address'], (string) $account->settings['from_name'])]);
            $envelope = null;
        }

        return self::smtp($account)->send($message, $envelope);
    }

    public static function smtp(MessagingProvider $account): EsmtpTransport
    {
        $transport = new EsmtpTransport((string) $account->settings['host'], (int) $account->settings['port'], ($account->settings['encryption'] ?? null) === 'ssl');
        if (filled($account->secrets['username'] ?? null)) {
            $transport->setUsername((string) $account->secrets['username']);
            $transport->setPassword((string) ($account->secrets['password'] ?? ''));
        }

        return $transport;
    }

    public function __toString(): string
    {
        return 'platform';
    }
}
