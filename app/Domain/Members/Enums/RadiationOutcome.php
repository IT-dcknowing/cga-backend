<?php

namespace App\Domain\Members\Enums;

use App\Support\Enums\HasLabel;

/**
 * Issue d'une radiation validée (RG-PTF-04) : dossier clos ou basculé dans le portefeuille Cabinet.
 */
enum RadiationOutcome: string
{
    use HasLabel;

    case Closed = 'closed';
    case MovedToCabinet = 'moved_to_cabinet';
}
