<?php

namespace App\Domain\Reports\Enums;

use App\Support\Enums\HasLabel;

/**
 * Personne de la fiche permanente du CGA (F-RPT-01) : dirigeant, salarié permanent, personnel
 * occasionnel.
 */
enum CgaPersonKind: string
{
    use HasLabel;

    case Director = 'director';
    case Employee = 'employee';
    case Occasional = 'occasional';
}
