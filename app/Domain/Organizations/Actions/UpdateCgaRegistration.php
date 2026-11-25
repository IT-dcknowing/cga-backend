<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Modification de la fiche d'un CGA au registre par le CNCGA (F-ORG-02) : dénomination, sigle, ville,
 * agrément (en cours ou agréé), contacts. Le retrait d'agrément passe par WithdrawAgreement ; un CGA
 * résilié ne se modifie plus.
 */
class UpdateCgaRegistration
{
    public function __construct(
        private readonly UpdateCgaProfile $profile,
        private readonly PhoneNormalizer $phones,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  fiche minimale validée, dont agreement_status
     *
     * @throws BusinessRuleException organization_terminated
     */
    public function execute(Organization $organization, array $attributes): Organization
    {
        $attributes = $this->phones->normalizeFields($attributes, ['phone_1']);

        return DB::transaction(function () use ($organization, $attributes): Organization {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            if ($organization->status === OrganizationStatus::Terminated) {
                throw new BusinessRuleException('organization_terminated');
            }

            $organization->fill($attributes);
            $organization->agreement_status = AgreementStatus::from((string) $attributes['agreement_status']);
            $this->profile->refreshCompletion($organization);
            $organization->save();

            return $organization;
        });
    }
}
