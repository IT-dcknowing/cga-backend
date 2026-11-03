<?php

namespace App\Domain\Notifications\Providers;

use App\Domain\Notifications\Contracts\WhatsappProvider;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Domain\Notifications\Support\MessagingProviders;
use Illuminate\Contracts\Container\Container;

/**
 * Fournisseur WhatsApp du compte actif de l'écran « Envois » (RG-NOT-05), résolu à chaque envoi : Cloud
 * API de Meta, ou journal (pilote « log », et tant qu'aucun compte n'est actif).
 */
class ConfiguredWhatsappProvider implements WhatsappProvider
{
    public function __construct(
        private readonly MessagingProviders $providers,
        private readonly Container $container,
    ) {}

    public function send(string $to, string $template, string $language, array $parameters): ?string
    {
        return $this->for($this->providers->active('whatsapp'))->send($to, $template, $language, $parameters);
    }

    public function for(?MessagingProvider $account): WhatsappProvider
    {
        return $account?->driver === 'whatsapp_cloud'
            ? new WhatsappCloudProvider($account)
            : $this->container->make(LogWhatsappProvider::class);
    }
}
