<?php

namespace App\Domain\Obligations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Effets d'une version du catalogue national (RG-DATA-04, décision 0005), prévus à l'aperçu ou
 * appliqués : obligations ajoutées, retirées, modifiées ; propositions, fins, échéances à venir
 * recalculées ; dossiers et centres concernés. Des nombres seuls : aucun nom d'adhérent.
 *
 * @property array<string, mixed> $resource
 */
class CatalogImpactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
