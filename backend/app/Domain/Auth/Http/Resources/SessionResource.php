<?php

namespace App\Domain\Auth\Http\Resources;

use App\Domain\Auth\Data\SessionState;
use App\Domain\Notifications\Enums\PreferenceChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Session du SPA (GET /api/v1/me, 02-TRD §6.2) : l'utilisateur, ses espaces, l'espace actif, le
 * dernier espace choisi (présélection du sélecteur, 03-APPFLOW §1) et les droits effectifs de
 * l'espace actif, d'après lesquels le SPA affiche ses menus (RG-ROLE-07).
 *
 * @property SessionState $resource
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource->user;
        $memberships = $this->resource->memberships;

        return [
            'user' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'email' => $user->email,
                'notification_preferences' => [
                    'sms' => $user->acceptsMessagesBy(PreferenceChannel::Sms),
                    'whatsapp' => $user->acceptsMessagesBy(PreferenceChannel::Whatsapp),
                ],
            ],
            'memberships' => MembershipResource::collection($memberships),
            'active_membership_id' => $this->resource->activeMembershipId,
            'last_membership_id' => $memberships->contains('id', $user->last_space_membership_id) ? $user->last_space_membership_id : null,
            /** @var list<string> */
            'permissions' => $this->resource->permissions,
        ];
    }
}
