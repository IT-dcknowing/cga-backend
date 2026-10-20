<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Création d'un CGA au registre par le CNCGA (F-ORG-02) : fiche minimale, statut d'agrément en cours
 * ou agréé. Un CGA à l'agrément en cours peut être créé et abonné, mais n'inscrit pas d'adhérents
 * (RG-ORG-03). Il n'a aucun accès tant qu'il n'est pas abonné (RG-ORG-06). Téléphone au format E.164.
 */
class CreateCga
{
    public function __construct(private readonly PhoneNormalizer $phones) {}

    /**
     * @param  array<string, mixed>  $attributes  fiche minimale validée, dont agreement_status
     */
    public function execute(array $attributes): Organization
    {
        $attributes = $this->phones->normalizeFields($attributes, ['phone_1']);

        return DB::transaction(static function () use ($attributes): Organization {
            $organization = new Organization($attributes);
            $organization->type = OrganizationType::Cga;
            $organization->agreement_status = AgreementStatus::from((string) $attributes['agreement_status']);
            $organization->save();

            return $organization->refresh();
        });
    }
}
