<?php

namespace App\Domain\Trainings\Enums;

use App\Support\Enums\HasLabel;

/**
 * Émargement d'un inscrit (F-FOR-04) : présent ou absent.
 */
enum Attendance: string
{
    use HasLabel;

    case Present = 'present';
    case Absent = 'absent';
}
