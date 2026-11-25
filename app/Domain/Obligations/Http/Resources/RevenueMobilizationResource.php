<?php

namespace App\Domain\Obligations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mobilisation des recettes du portefeuille (RevenueMobilization) : période, totaux, lignes par nature
 * d'impôt et par contribuable (les plus gros restes dus d'abord, au plus
 * `cga.obligations.revenue_members_limit`), nombre total de contribuables.
 *
 * @property array{from: string, to: string, totals: array<string, int|float|null>, natures: list<array<string, mixed>>, members: list<array<string, mixed>>, members_total: int} $resource
 */
class RevenueMobilizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $revenue = $this->resource;

        return [
            'from' => $revenue['from'],
            'to' => $revenue['to'],
            'totals' => $revenue['totals'],
            'natures' => $revenue['natures'],
            'members' => $revenue['members'],
            'members_total' => $revenue['members_total'],
        ];
    }
}
