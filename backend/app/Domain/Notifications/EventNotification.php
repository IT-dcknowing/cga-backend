<?php

namespace App\Domain\Notifications;

/**
 * Notification d'un événement de la table PRD M13 : type déclaré (canaux, essentielle, contractuelle,
 * modèle WhatsApp), valeurs de ses textes et écran à ouvrir. Une même classe sert tous les types : les
 * textes sont dans lang/fr/notifications.php, les règles d'envoi dans config/notifications.php.
 */
final class EventNotification extends PlatformNotification
{
    /**
     * @param  array<string, string|int>  $values  valeurs des textes (et variables du modèle WhatsApp, dans l'ordre)
     */
    public function __construct(
        string $organizationId,
        ?string $membershipId,
        private string $kind,
        private array $values = [],
        private ?string $path = null,
    ) {
        parent::__construct($organizationId, $membershipId);
    }

    public function type(): string
    {
        return $this->kind;
    }

    public function replacements(): array
    {
        return $this->values;
    }

    public function link(): ?string
    {
        return $this->path;
    }
}
