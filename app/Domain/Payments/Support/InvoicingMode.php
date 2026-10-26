<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Models\InvoicingProvider;

/**
 * Mode de certification en vigueur d'une organisation (RG-FNE-03) : celui de son paramétrage
 * « Facturation FNE » s'il est actif, sinon le mode manuel, mode par défaut au lancement.
 */
class InvoicingMode
{
    public function provider(string $organizationId): ?InvoicingProvider
    {
        return InvoicingProvider::query()->where('organization_id', $organizationId)->first();
    }

    public function current(string $organizationId): CertificationMode
    {
        $provider = $this->provider($organizationId);

        return $provider !== null && $provider->active ? $provider->mode : CertificationMode::Manual;
    }
}
