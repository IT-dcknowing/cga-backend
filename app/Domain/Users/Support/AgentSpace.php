<?php

namespace App\Domain\Users\Support;

use App\Domain\Organizations\Enums\OrganizationType;
use Illuminate\Http\Request;

/**
 * Espace d'une route des agents : /api/v1/network/users (CNCGA) ou /api/v1/cga/users (CGA), d'après
 * le nom de la route.
 */
final class AgentSpace
{
    public static function of(Request $request): OrganizationType
    {
        return str_starts_with((string) $request->route()?->getName(), 'network.') ? OrganizationType::Cncga : OrganizationType::Cga;
    }
}
