<?php

namespace App\Domain\Statistics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Statistiques nationales (F-STA-01) : période, couverture, date du calcul, indicateurs macro, recettes fiscales, répartitions par CGA, régime, secteur et Direction régionale. Seules les clés de la liste blanche sortent ; un bloc absent (droit manquant) n'apparaît pas.
 *
 * @property array<string, mixed> $resource
 */
class NationalStatisticsResource extends JsonResource
{
    private const KEYS = ['period', 'coverage', 'computed_at', 'macro', 'tax_revenue', 'by_cga', 'by_regime', 'by_sector', 'by_directorate'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip(self::KEYS));
    }
}
