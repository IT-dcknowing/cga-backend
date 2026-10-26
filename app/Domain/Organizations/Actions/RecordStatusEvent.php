<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\OrganizationStatusEvent as Event;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationStatusEvent;
use App\Domain\Users\Models\User;

/**
 * Inscrit un événement dans l'historique du CGA (F-ORG-05 à 07) : motif, auteur, date. Appelée dans la
 * transaction de l'Action qui change l'état.
 */
class RecordStatusEvent
{
    public function execute(Organization $organization, Event $event, string $reason, User $actor): OrganizationStatusEvent
    {
        $entry = new OrganizationStatusEvent;
        $entry->organization_id = $organization->id;
        $entry->event = $event;
        $entry->reason = $reason;
        $entry->actor_id = $actor->id;
        $entry->occurred_at = now();
        $entry->save();

        return $entry;
    }
}
