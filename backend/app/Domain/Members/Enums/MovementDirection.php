<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Sens d'une bascule entre portefeuilles (RG-PTF-03 à 05).
 */
enum MovementDirection: string
{
    use HasLabel;

    case CabinetToCga = 'cabinet_to_cga';
    case CgaToCabinet = 'cga_to_cabinet';
}
