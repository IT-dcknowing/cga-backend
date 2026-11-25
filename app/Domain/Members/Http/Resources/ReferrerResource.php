<?php

namespace App\Domain\Members\Http\Resources;

use App\Domain\Members\Models\BusinessReferrer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Apporteur d'affaires du centre (RG-PTF-06) : libellé « Accessoire n », personne, contact, conditions,
 * état, nombre de dossiers du portefeuille CGA rattachés, actions permises.
 *
 * @property BusinessReferrer $resource
 */
class ReferrerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $referrer = $this->resource;

        return [
            'id' => $referrer->id,
            'label' => $referrer->label,
            'name' => $referrer->name,
            'phone' => $referrer->phone,
            'email' => $referrer->email,
            'terms' => $referrer->terms,
            'active' => $referrer->active,
            'members_count' => (int) ($referrer->getAttribute('members_count') ?? 0),
            'can' => [
                'update' => Gate::allows('update', $referrer),
                'delete' => Gate::allows('delete', $referrer) && (int) ($referrer->getAttribute('members_count') ?? 0) === 0,
            ],
        ];
    }
}
