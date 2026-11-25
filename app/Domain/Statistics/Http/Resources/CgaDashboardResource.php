<?php

namespace App\Domain\Statistics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tableau de bord du CGA (F-DSH-02) : période, date du calcul, indicateurs, alertes. Seules les clés de la liste blanche sortent ; un bloc absent (droit manquant) n'apparaît pas.
 *
 * @property array<string, mixed> $resource
 */
class CgaDashboardResource extends JsonResource
{
    private const KEYS = ['period', 'computed_at', 'indicators', 'alerts'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip(self::KEYS));
    }
}
