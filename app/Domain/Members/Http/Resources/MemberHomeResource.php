<?php

namespace App\Domain\Members\Http\Resources;

use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Accueil de l'adhérent (03-APPFLOW §5.2) : son dossier (raison sociale, numéro, portefeuille, statut,
 * fin d'adhésion après une radiation validée) et l'identité de son CGA (nom, contacts). Jamais
 * l'apporteur (RG-PTF-06), ni rien d'interne au centre.
 *
 * @property Member $resource
 */
class MemberHomeResource extends JsonResource
{
    public function __construct(
        Member $member,
        private readonly Organization $organization,
        private readonly Membership $access,
    ) {
        parent::__construct($member);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $member = $this->resource;
        $radiation = $member->relationLoaded('currentRadiation') ? $member->currentRadiation : null;
        $ended = $radiation !== null && $radiation->status === RadiationStatus::Validated;

        return [
            'member' => [
                'company_name' => $member->company_name,
                'sigle' => $member->sigle,
                'number' => $member->membership_number ?? $member->client_number,
                'portfolio' => $member->portfolio->value,
                'portfolio_label' => $member->portfolio->label(),
                'status' => $member->status->value,
                'status_label' => $member->status->label(),
                'regime_label' => $member->regime->label(),
                'adhesion_date' => $member->adhesion_date?->toDateString(),
                'ended_on' => $ended ? $radiation->effective_date->toDateString() : null,
            ],
            'access' => [
                'role' => $this->access->role->value,
                'role_label' => $this->access->role->label(),
                // Après la fin de l'adhésion, consultation seule jusqu'à cette date.
                'read_only_until' => $this->access->read_only_until?->toDateString(),
            ],
            'organization' => [
                'name' => $this->organization->name,
                'sigle' => $this->organization->sigle,
                'address' => $this->organization->address,
                'city' => $this->organization->city,
                'phone_1' => $this->organization->phone_1,
                'phone_2' => $this->organization->phone_2,
                'email' => $this->organization->email,
            ],
        ];
    }
}
