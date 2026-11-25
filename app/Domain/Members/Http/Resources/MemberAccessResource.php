<?php

namespace App\Domain\Members\Http\Resources;

use App\Domain\Auth\Models\Invitation;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Accès en ligne d'un adhérent (F-ADH-09) : principal ou secondaire, personne, état (en invitation,
 * actif, consultation seule après radiation, désactivé), invitation en cours, actions permises.
 *
 * @property Membership $resource
 */
class MemberAccessResource extends JsonResource
{
    public function __construct(Membership $access, private readonly bool $manageable)
    {
        parent::__construct($access);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $access = $this->resource;
        $invitation = $access->invitations
            ->filter(static fn (Invitation $invitation): bool => $invitation->accepted_at === null && $invitation->revoked_at === null && $invitation->expires_at->isFuture())
            ->sortByDesc('sent_at')
            ->first();

        return [
            'id' => $access->id,
            'role' => $access->role->value,
            'role_label' => $access->role->label(),
            'first_name' => $access->user->first_name,
            'last_name' => $access->user->last_name,
            'phone' => $access->user->phone,
            'email' => $access->user->email,
            'status' => $access->status->value,
            'status_label' => $access->status->label(),
            'read_only_until' => $access->read_only_until?->toDateString(),
            'activated_at' => $access->activated_at?->toIso8601ZuluString(),
            'invitation' => $invitation === null ? null : [
                'channel' => $invitation->channel->value,
                'sent_at' => $invitation->sent_at->toIso8601ZuluString(),
                'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
            ],
            'can' => [
                'resend_invitation' => $this->manageable && $access->status === MembershipStatus::Invited,
                'disable' => $this->manageable && $access->status !== MembershipStatus::Disabled,
                'enable' => $this->manageable && $access->status === MembershipStatus::Disabled,
            ],
        ];
    }
}
