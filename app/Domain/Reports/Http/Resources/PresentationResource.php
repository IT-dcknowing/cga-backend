<?php

namespace App\Domain\Reports\Http\Resources;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche technique du CGA, section 1 du rapport trimestriel (F-RPT-01) : identité tirée de la fiche du
 * centre (lecture) ; présentation, historique et chiffre d'affaires N-1 tenus dans la fiche permanente.
 *
 * @property Organization $resource
 */
class PresentationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cga = $this->resource;

        return [
            'name' => $cga->name,
            'sigle' => $cga->sigle,
            'legal_form' => $cga->legal_form,
            'share_capital' => $cga->share_capital,
            'ncc' => $cga->ncc,
            'agreement_number' => $cga->agreement_number,
            'agreement_date' => $cga->agreement_date?->toDateString(),
            'regional_directorate' => $cga->regionalDirectorate === null ? null : ['id' => $cga->regionalDirectorate->id, 'name' => $cga->regionalDirectorate->name],
            'said' => $cga->said,
            'address' => $cga->address,
            'city' => $cga->city,
            'manager_name' => $cga->manager_name,
            'expert_name' => $cga->expert_name,
            'history_text' => $cga->history_text,
            'previous_year_revenue' => $cga->previous_year_revenue,
        ];
    }
}
