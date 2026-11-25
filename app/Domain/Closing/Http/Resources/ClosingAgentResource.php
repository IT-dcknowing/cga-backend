<?php

namespace App\Domain\Closing\Http\Resources;

use App\Domain\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Agent du centre à qui confier des dossiers de la campagne de clôture (« Affecter à un agent »,
 * filtre par agent de /cga/cloture) : identifiant et nom, rien d'autre.
 */
class ClosingAgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->fullName(),
        ];
    }
}
