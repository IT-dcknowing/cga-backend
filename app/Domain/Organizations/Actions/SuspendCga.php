<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationStatusEvent as Event;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Suspension d'un CGA par la Présidente (F-ORG-06) : motif obligatoire, tracé, réversible. Ses agents
 * ne peuvent plus que consulter, ses adhérents gardent l'accès à leurs documents (RG-ORG-08). Jamais
 * automatique (RG-ORG-09) : seule une action de la Présidente l'appelle.
 */
class SuspendCga
{
    public function __construct(private readonly RecordStatusEvent $record) {}

    /**
     * @throws BusinessRuleException organization_already_suspended, organization_terminated
     */
    public function execute(Organization $organization, string $reason, User $actor): Organization
    {
        return DB::transaction(function () use ($organization, $reason, $actor): Organization {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->suspend($organization, $reason, $actor);

            return $organization;
        });
    }

    /**
     * Suspension dans la transaction de l'appelant (aussi utilisée par le retrait d'agrément).
     *
     * @throws BusinessRuleException organization_already_suspended, organization_terminated
     */
    public function suspend(Organization $organization, string $reason, User $actor): void
    {
        match ($organization->status) {
            OrganizationStatus::Terminated => throw new BusinessRuleException('organization_terminated'),
            OrganizationStatus::Suspended => throw new BusinessRuleException('organization_already_suspended'),
            OrganizationStatus::Active => null,
        };

        $organization->status = OrganizationStatus::Suspended;
        $organization->suspended_at = now();
        $organization->suspension_reason = $reason;
        $organization->save();

        $this->record->execute($organization, Event::Suspended, $reason, $actor);
    }
}
