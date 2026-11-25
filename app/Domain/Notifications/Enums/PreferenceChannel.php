<?php

namespace App\Domain\Notifications\Enums;

use App\Support\Enums\HasLabel;

/**
 * Canaux qu'un utilisateur peut refuser pour les messages non essentiels (RG-NOT-04).
 */
enum PreferenceChannel: string
{
    use HasLabel;

    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
}
