<?php

namespace App\Domain\Announcements\Http\Requests\Concerns;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Support\Tenancy\TenantContext;

/**
 * Espace de l'auteur désigné par la route : /network/announcements pour le CNCGA, /cga/announcements pour un CGA.
 */
trait AuthorSpace
{
    protected function authorSpace(): bool
    {
        $context = app(TenantContext::class);

        return $context->has() && $context->organizationType() === ($this->routeIs('network.*') ? OrganizationType::Cncga : OrganizationType::Cga);
    }
}
