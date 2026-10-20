<?php

namespace App\Domain\Organizations\Enums;

use App\Support\Enums\HasLabel;

/**
 * CNCGA, CGA, ou organisation technique « plateforme » (05-BACKEND-SCHEMA §3).
 */
enum OrganizationType: string
{
    use HasLabel;

    case Platform = 'platform';
    case Cncga = 'cncga';
    case Cga = 'cga';
}
