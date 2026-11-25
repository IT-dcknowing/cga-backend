<?php

namespace App\Domain\Statistics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tableau de bord du CNCGA (F-DSH-01) : période, couverture, date du calcul, indicateurs, graphique mensuel des cotisations, alertes. Seules les clés de la liste blanche sortent ; un bloc absent (droit manquant) n'apparaît pas.
 *
 * @property array<string, mixed> $resource
 */
class NetworkDashboardResource extends JsonResource
{
    private const KEYS = ['period', 'coverage', 'computed_at', 'indicators', 'dues_chart', 'alerts'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip(self::KEYS));
    }
}
