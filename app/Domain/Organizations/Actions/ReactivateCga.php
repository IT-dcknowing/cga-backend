<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationStatusEvent as Event;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Réactivation d'un CGA suspendu par la Présidente (F-ORG-06) : motif obligatoire, tracé.
 */
class ReactivateCga
{
    public function __construct(private readonly RecordStatusEvent $record) {}

    /**
     * @throws BusinessRuleException organization_not_suspended
     */
    public function execute(Organization $organization, string $reason, User $actor): Organization
    {
        return DB::transaction(function () use ($organization, $reason, $actor): Organization {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            if ($organization->status !== OrganizationStatus::Suspended) {
                throw new BusinessRuleException('organization_not_suspended');
            }

            $organization->status = OrganizationStatus::Active;
            $organization->suspended_at = null;
            $organization->suspension_reason = null;
            $organization->save();

            $this->record->execute($organization, Event::Reactivated, $reason, $actor);

            return $organization;
        });
    }
}
