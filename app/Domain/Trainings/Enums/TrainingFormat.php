<?php

namespace App\Domain\Trainings\Enums;

use App\Support\Enums\HasLabel;

/**
 * Format d'une formation (F-FOR-01) : présentiel, en ligne, atelier.
 */
enum TrainingFormat: string
{
    use HasLabel;

    case InPerson = 'in_person';
    case Online = 'online';
    case Workshop = 'workshop';
}
