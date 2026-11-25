<?php

namespace App\Domain\Auth\Http\Resources;

use App\Domain\Users\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un espace de l'utilisateur, tel que l'affiche le sélecteur d'espace (F-AUTH-04, 03-APPFLOW §2).
 *
 * @property Membership $resource
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->resource;
        $organization = $membership->organization;

        return [
            'id' => $membership->id,
            'role' => $membership->role->value,
            'role_label' => $membership->role->label(),
            'status' => $membership->status->value,
            'organization' => [
                'id' => $organization->id,
                'type' => $organization->type->value,
                'name' => $organization->name,
                'banking_enabled' => $organization->banking_enabled,
            ],
            // Nom de l'entreprise ajouté avec la table des adhérents (étape 2.1).
            'member' => $membership->member_id === null ? null : ['id' => $membership->member_id, 'name' => null],
            // Compteur branché avec le moteur de notifications (étape 6.1).
            'unread_notifications' => 0,
        ];
    }
}
