<?php

namespace App\Domain\Reports\Http\Resources;

use App\Domain\Reports\Models\CgaPerson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Personne de la fiche permanente du CGA (F-RPT-01).
 *
 * @property CgaPerson $resource
 */
class CgaPersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $person = $this->resource;

        return [
            'id' => $person->id,
            'kind' => $person->kind->value,
            'kind_label' => $person->kind->label(),
            'full_name' => $person->full_name,
            'nationality' => $person->nationality,
            'profession' => $person->profession,
            'diplomas' => $person->diplomas,
            'function_in_cga' => $person->function_in_cga,
            'start_date' => $person->start_date?->toDateString(),
            'practice_structure' => $person->practice_structure,
            'practice_function' => $person->practice_function,
            'practice_years' => $person->practice_years,
            'cnps_declared' => $person->cnps_declared,
            'active' => $person->active,
            'end_date' => $person->end_date?->toDateString(),
        ];
    }
}
