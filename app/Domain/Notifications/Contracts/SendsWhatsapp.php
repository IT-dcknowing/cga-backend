<?php

namespace App\Domain\Notifications\Contracts;

/**
 * Notification qui peut partir par le canal « whatsapp » : clé du modèle pré-approuvé et valeurs de ses
 * variables.
 */
interface SendsWhatsapp
{
    /**
     * @return array{template: string, parameters: list<string>}
     */
    public function toWhatsapp(object $notifiable): array;
}
