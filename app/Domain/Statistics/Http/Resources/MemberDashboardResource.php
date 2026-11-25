<?php

namespace App\Domain\Statistics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tableau de bord de l'adhérent (F-DSH-03) : prochaine échéance, impôts dus, CNPS + CMU dus, échéances du mois, honoraires, dernier message, documents récents (blocs selon les droits). Seules les clés de la liste blanche sortent ; un bloc absent (droit manquant) n'apparaît pas.
 *
 * @property array<string, mixed> $resource
 */
class MemberDashboardResource extends JsonResource
{
    private const KEYS = ['next_due', 'taxes_due', 'social_due', 'month_dues', 'fees', 'last_message', 'recent_documents'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip(self::KEYS));
    }
}
