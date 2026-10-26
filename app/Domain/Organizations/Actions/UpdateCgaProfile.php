<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Complément de la fiche par le gérant du CGA (F-ORG-03). La fiche est complète quand les champs
 * exigés sont remplis (RG-ORG-04) : profile_completed_at est alors daté, et effacé si un champ exigé
 * redevient vide. L'abréviation de numérotation ne change plus après le premier numéro d'adhésion
 * (RG-ORG-05). Téléphones au format E.164.
 */
class UpdateCgaProfile
{
    public function __construct(private readonly PhoneNormalizer $phones) {}

    /**
     * @param  array<string, mixed>  $attributes  champs de la fiche validés
     *
     * @throws BusinessRuleException abbreviation_locked, organization_terminated
     */
    public function execute(Organization $organization, array $attributes): Organization
    {
        $attributes = $this->phones->normalizeFields($attributes, ['phone_1', 'phone_2']);

        return DB::transaction(function () use ($organization, $attributes): Organization {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            if ($organization->status === OrganizationStatus::Terminated) {
                throw new BusinessRuleException('organization_terminated');
            }

            $abbreviation = $attributes['numbering_abbreviation'] ?? $organization->numbering_abbreviation;
            if ($organization->abbreviation_locked_at !== null && $abbreviation !== $organization->numbering_abbreviation) {
                throw new BusinessRuleException('abbreviation_locked');
            }

            $organization->fill(array_diff_key($attributes, ['numbering_abbreviation' => true]));
            $organization->numbering_abbreviation = is_string($abbreviation) ? $abbreviation : null;
            $this->refreshCompletion($organization);
            $organization->save();

            return $organization;
        });
    }

    /**
     * Date (ou efface) la complétude de la fiche d'après les champs exigés (RG-ORG-04).
     */
    public function refreshCompletion(Organization $organization): void
    {
        if ($organization->missingProfileFields() !== []) {
            $organization->profile_completed_at = null;
        } elseif ($organization->profile_completed_at === null) {
            $organization->profile_completed_at = now();
        }
    }
}
