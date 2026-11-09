<?php

namespace App\Domain\Concerns\Enums;

use App\Support\Enums\HasLabel;

/**
 * Niveau d'une préoccupation (PRD M10) : d'un adhérent à son CGA, ou d'un CGA au CNCGA.
 */
enum ConcernLevel: string
{
    use HasLabel;

    case MemberToCga = 'member_to_cga';
    case CgaToCncga = 'cga_to_cncga';
}
