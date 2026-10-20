<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre global du cloisonnement : seules les données de l'organisation active (02-TRD §6.3).
 *
 * @implements Scope<Model>
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenancy::isBypassed()) {
            return;
        }

        $builder->where($model->qualifyColumn('organization_id'), app(TenantContext::class)->organizationId());
    }
}
