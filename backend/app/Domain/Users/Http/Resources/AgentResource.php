<?php

namespace App\Domain\Users\Http\Resources;

use App\Domain\Auth\Models\Invitation;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\AgentDirectory;
use App\Domain\Users\Support\AgentSpace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Agent d'une organisation (F-ORG-09, F-ORG-10) : identité, état dans l'organisation (actif, invité,
 * désactivé), rôles avec leur état, dernière connexion, invitation en cours, actions permises.
 *
 * @property User $resource
 */
class AgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $agent = $this->resource;
        $memberships = $agent->memberships;
        $status = AgentDirectory::status($memberships);
        $space = AgentSpace::of($request);
        $manageable = Gate::allows('update', [$agent, $space]) && ! $agent->is($request->user());
        $invitation = $memberships
            ->filter(static fn (Membership $membership): bool => $membership->status === MembershipStatus::Invited)
            ->flatMap(static fn (Membership $membership) => $membership->invitations)
            ->filter(static fn (Invitation $invitation): bool => $invitation->accepted_at === null && $invitation->revoked_at === null && $invitation->expires_at->isFuture())
            ->sortByDesc('sent_at')
            ->first();

        return [
            'id' => $agent->id,
            'first_name' => $agent->first_name,
            'last_name' => $agent->last_name,
            'name' => $agent->fullName(),
            'phone' => $agent->phone,
            'email' => $agent->email,
            'status' => $status->value,
            'status_label' => $status->label(),
            'roles' => $memberships->map(static fn (Membership $membership): array => [
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'status' => $membership->status->value,
            ])->values()->all(),
            'last_login_at' => $agent->last_login_at?->toIso8601ZuluString(),
            'invitation' => $invitation === null ? null : [
                'channel' => $invitation->channel->value,
                'sent_at' => $invitation->sent_at->toIso8601ZuluString(),
                'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
            ],
            'can' => [
                'update_roles' => $manageable,
                'disable' => $manageable && $status !== MembershipStatus::Disabled,
                'enable' => $manageable && $status === MembershipStatus::Disabled,
                'resend_invitation' => $manageable && $status === MembershipStatus::Invited,
            ],
        ];
    }
}
