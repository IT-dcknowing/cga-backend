<?php

namespace App\Domain\Statistics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Position du CGA dans le réseau (F-STA-02) : période, date du calcul, moyennes du réseau (contributeurs, seuil RG-STA-04), indicateurs du CGA et moyennes. Seules les clés de la liste blanche sortent ; un bloc absent (droit manquant) n'apparaît pas.
 *
 * @property array<string, mixed> $resource
 */
class CgaPositionResource extends JsonResource
{
    private const KEYS = ['period', 'computed_at', 'network', 'indicators'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip(self::KEYS));
    }
}
