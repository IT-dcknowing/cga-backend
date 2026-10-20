<?php

namespace App\Support\Tenancy;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Donnée appartenant à une organisation (02-TRD §6.3) : filtre global sur l'organisation active,
 * organisation renseignée par le serveur à la création, jamais changée ensuite. Sans espace actif, la
 * lecture et l'écriture sont refusées, sauf dans un Tenancy::bypass() journalisé.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(static function (Model $model): void {
            $current = $model->getAttribute('organization_id');

            if (Tenancy::isBypassed()) {
                if (blank($current)) {
                    throw new TenancyViolationException('Création hors cloisonnement sans organisation explicite.');
                }

                return;
            }

            $active = app(TenantContext::class)->organizationId();
            if (blank($current)) {
                $model->setAttribute('organization_id', $active);
            } elseif ($current !== $active) {
                throw new TenancyViolationException("Création refusée dans l'organisation {$current} depuis l'espace {$active}.");
            }
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty('organization_id') && ! Tenancy::isBypassed()) {
                throw new TenancyViolationException("L'organisation d'une donnée ne change jamais.");
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
