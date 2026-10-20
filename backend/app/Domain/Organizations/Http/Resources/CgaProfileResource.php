<?php

namespace App\Domain\Organizations\Http\Resources;

use App\Domain\Documents\Models\File;
use App\Domain\Organizations\Models\Organization;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Fiche du CGA de l'espace actif (F-ORG-03) : identité complète, rattachements, images (liens de
 * téléchargement signés), champs exigés encore vides (RG-ORG-04), verrou de l'abréviation
 * (RG-ORG-05) et états affichés en bandeau (suspendu, agrément en cours).
 *
 * @property Organization $resource
 */
class CgaProfileResource extends JsonResource
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
            'legal_form' => $organization->legal_form,
            'share_capital' => $organization->share_capital,
            'ncc' => $organization->ncc,
            'rccm' => $organization->rccm,
            'agreement_status' => $organization->agreement_status->value,
            'agreement_status_label' => $organization->agreement_status->label(),
            'agreement_number' => $organization->agreement_number,
            'agreement_date' => $organization->agreement_date?->toDateString(),
            'regional_directorate' => $organization->regionalDirectorate === null ? null : [
                'id' => $organization->regionalDirectorate->id,
                'code' => $organization->regionalDirectorate->code,
                'name' => $organization->regionalDirectorate->name,
            ],
            'tax_office' => $organization->taxOffice === null ? null : [
                'id' => $organization->taxOffice->id,
                'code' => $organization->taxOffice->code,
                'name' => $organization->taxOffice->name,
            ],
            'commune' => $organization->commune === null ? null : [
                'id' => $organization->commune->id,
                'name' => $organization->commune->name,
            ],
            'said' => $organization->said,
            'address' => $organization->address,
            'city' => $organization->city,
            'phone_1' => $organization->phone_1,
            'phone_2' => $organization->phone_2,
            'email' => $organization->email,
            'manager_name' => $organization->manager_name,
            'expert_name' => $organization->expert_name,
            'signatory_name' => $organization->signatory_name,
            'signatory_title' => $organization->signatory_title,
            'numbering_abbreviation' => $organization->numbering_abbreviation,
            'abbreviation_locked' => $organization->abbreviation_locked_at !== null,
            'history_text' => $organization->history_text,
            'previous_year_revenue' => $organization->previous_year_revenue,
            'images' => [
                'logo' => $this->image($organization->logo),
                'signature' => $this->image($organization->signature),
                'stamp' => $this->image($organization->stamp),
            ],
            'status' => $organization->status->value,
            'status_label' => $organization->status->label(),
            'subscription_status' => $organization->subscription_status->value,
            'subscription_status_label' => $organization->subscription_status->label(),
            'missing_fields' => $organization->missingProfileFields(),
            'profile_completed_at' => $organization->profile_completed_at?->toIso8601ZuluString(),
            'can' => [
                'update' => Gate::allows('updateProfile', $organization),
            ],
        ];
    }

    /**
     * @return array{id: string, mime_type: string, url: string}|null
     */
    private function image(?File $file): ?array
    {
        return $file === null ? null : [
            'id' => $file->id,
            'mime_type' => $file->mime_type,
            'url' => app(StorageService::class)->temporaryUrl($file),
        ];
    }
}
