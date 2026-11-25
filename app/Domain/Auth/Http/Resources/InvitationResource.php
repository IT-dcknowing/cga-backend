<?php

namespace App\Domain\Auth\Http\Resources;

use App\Domain\Auth\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invitation lue depuis son lien (F-AUTH-02, 03-APPFLOW §2) : de quoi afficher « Bienvenue
 * {prénom}, {organisation} vous invite en tant que {rôle} » ; si le lien n'est plus valide, seulement
 * le contact de l'organisation qui invite.
 *
 * @property Invitation $resource
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invitation = $this->resource;

        if (! $invitation->isUsable()) {
            return ['status' => 'invalid', 'organization_contact' => $invitation->organizationContact()];
        }

        $membership = $invitation->membership;

        return [
            'status' => 'valid',
            'first_name' => $membership->user->first_name,
            'organization_name' => $membership->organization->name,
            'organization_contact' => $invitation->organizationContact(),
            'role' => $membership->role->value,
            'role_label' => $membership->role->label(),
            'is_member' => $membership->role->isMemberRole(),
        ];
    }
}
