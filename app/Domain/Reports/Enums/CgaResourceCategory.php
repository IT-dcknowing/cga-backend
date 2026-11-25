<?php

namespace App\Domain\Reports\Enums;

use App\Support\Enums\HasLabel;

/**
 * Moyens matériels de la fiche permanente du CGA (F-RPT-01) : une ligne par catégorie.
 */
enum CgaResourceCategory: string
{
    use HasLabel;

    case Premises = 'premises';
    case TrainingRooms = 'training_rooms';
    case Furniture = 'furniture';
    case Computers = 'computers';
    case Software = 'software';
    case Projectors = 'projectors';
    case Vehicles = 'vehicles';
    case Other = 'other';
}
