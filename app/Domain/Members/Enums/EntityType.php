<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Type d'adhérent (F-ADH-02) : personne morale ou entreprise individuelle.
 */
enum EntityType: string
{
    use HasLabel;

    case Company = 'company';
    case Individual = 'individual';
}
