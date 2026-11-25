<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationStatusEvent as Event;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Perte d'agrément d'un CGA, marquée par la Présidente (F-ORG-07) : agrément « retiré » à la date
 * donnée, avec motif, et CGA suspendu (s'il ne l'est pas déjà). Aucun dossier n'est radié : la liste
 * des adhérents à radier vient avec les radiations, chacune saisie et validée à la main (RG-ADH-18).
 */
class WithdrawAgreement
{
    public function __construct(
        private readonly RecordStatusEvent $record,
        private readonly SuspendCga $suspend,
    ) {}

    /**
     * @throws BusinessRuleException agreement_already_withdrawn, organization_terminated
     */
    public function execute(Organization $organization, CarbonInterface $withdrawnAt, string $reason, User $actor): Organization
    {
        return DB::transaction(function () use ($organization, $withdrawnAt, $reason, $actor): Organization {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            if ($organization->status === OrganizationStatus::Terminated) {
                throw new BusinessRuleException('organization_terminated');
            }
            if ($organization->agreement_status === AgreementStatus::Withdrawn) {
                throw new BusinessRuleException('agreement_already_withdrawn');
            }

            $organization->agreement_status = AgreementStatus::Withdrawn;
            $organization->agreement_withdrawn_at = $withdrawnAt;
            $organization->save();
            $this->record->execute($organization, Event::AgreementWithdrawn, $reason, $actor);

            if ($organization->status === OrganizationStatus::Active) {
                $this->suspend->suspend($organization, $reason, $actor);
            }

            return $organization;
        });
    }
}
