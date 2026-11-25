<?php

namespace App\Domain\Organizations\Http\Resources;

use App\Domain\Members\Support\RegulatoryMemberCounts;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationStatusEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * CGA du registre, vu par le CNCGA (F-ORG-01, onglet Identité de F-ORG-04) : identité, agrément,
 * statut, abonnement et ses dates, complétude de la fiche, historique des changements d'état (fiche
 * détaillée), et les actions permises (bloc can, 02-TRD §4.3).
 *
 * @property Organization $resource
 */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $organization = $this->resource;

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'sigle' => $organization->sigle,
            'city' => $organization->city,
            'phone_1' => $organization->phone_1,
            'email' => $organization->email,
            'regional_directorate' => $organization->regionalDirectorate === null ? null : [
                'id' => $organization->regionalDirectorate->id,
                'code' => $organization->regionalDirectorate->code,
                'name' => $organization->regionalDirectorate->name,
            ],
            'agreement_status' => $organization->agreement_status->value,
            'agreement_status_label' => $organization->agreement_status->label(),
            'agreement_number' => $organization->agreement_number,
            'agreement_date' => $organization->agreement_date?->toDateString(),
            'agreement_withdrawn_at' => $organization->agreement_withdrawn_at?->toDateString(),
            'status' => $organization->status->value,
            'status_label' => $organization->status->label(),
            'suspended_at' => $organization->suspended_at?->toIso8601ZuluString(),
            'suspension_reason' => $organization->suspension_reason,
            'subscription' => [
                'status' => $organization->subscription_status->value,
                'status_label' => $organization->subscription_status->label(),
                'started_at' => $organization->subscription_started_at?->toDateString(),
                'trial_ends_at' => $organization->trial_ends_at?->toDateString(),
                'ended_at' => $organization->subscription_ended_at?->toDateString(),
                'data_deletion_due_at' => $organization->data_deletion_due_at?->toDateString(),
            ],
            // Toujours calculé, jamais saisi (RG-ORG-02) : portefeuille CGA seulement (RG-SEC-08), préchargé pour une liste.
            'members_count' => $this->membersCount($organization),
            'profile_completed_at' => $organization->profile_completed_at?->toIso8601ZuluString(),
            'data_status' => $organization->data_status->value,
            'created_at' => $organization->created_at?->toIso8601ZuluString(),
            'manager' => $this->whenLoaded('manager', fn (): ?array => $organization->manager === null ? null : [
                'id' => $organization->manager->user->id,
                'name' => $organization->manager->user->fullName(),
                'phone' => $organization->manager->user->phone,
                'email' => $organization->manager->user->email,
                'status' => $organization->manager->status->value,
                'status_label' => $organization->manager->status->label(),
            ]),
            'status_events' => $this->whenLoaded('statusEvents', fn (): array => $organization->statusEvents
                ->sortBy([['occurred_at', 'desc'], ['id', 'desc']])
                ->map(static fn (OrganizationStatusEvent $event): array => [
                    'event' => $event->event->value,
                    'event_label' => $event->event->label(),
                    'reason' => $event->reason,
                    'actor' => $event->actor === null ? null : [
                        'id' => $event->actor->id,
                        'name' => $event->actor->fullName(),
                    ],
                    'occurred_at' => $event->occurred_at->toIso8601ZuluString(),
                ])
                ->values()
                ->all()),
            'can' => [
                'update' => Gate::allows('update', $organization),
                'change_subscription' => Gate::allows('changeSubscription', $organization),
                'suspend' => Gate::allows('suspend', $organization),
                'invite_manager' => Gate::allows('inviteManager', $organization),
            ],
        ];
    }

    private function membersCount(Organization $organization): int
    {
        if ($organization->getAttribute('members_count') === null) {
            app(RegulatoryMemberCounts::class)->load(new Collection([$organization]));
        }

        return (int) $organization->getAttribute('members_count');
    }
}
