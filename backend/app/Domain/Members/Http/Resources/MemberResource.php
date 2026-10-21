<?php

namespace App\Domain\Members\Http\Resources;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Members\Support\MembershipReadiness;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Dossier d'un adhérent (F-ADH-03) : portefeuille, statut, numéros, contrat CGA, identité, fiscalité,
 * localisation, contacts, honoraires, état des pièces, chiffre d'affaires de référence et actions
 * permises. L'apporteur n'est montré qu'avec le droit members.referrers.view (RG-PTF-06).
 *
 * @property Member $resource
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $member = $this->resource;
        $context = app(TenantContext::class);
        $revenue = $member->relationLoaded('revenues') ? $member->revenues->sortByDesc('year')->first() : null;

        return [
            'id' => $member->id,
            'portfolio' => $member->portfolio->value,
            'portfolio_label' => $member->portfolio->label(),
            'status' => $member->status->value,
            'status_label' => $member->status->label(),
            'membership_number' => $member->membership_number,
            'client_number' => $member->client_number,
            'legacy_number' => $member->legacy_number,
            'referrer' => $this->when($context->has() && $context->allows('members.referrers.view'), fn (): ?array => $member->referrer === null ? null : [
                'id' => $member->referrer->id,
                'label' => $member->referrer->label,
                'name' => $member->referrer->name,
            ]),
            'contract' => [
                'signed_on' => $member->cga_contract_signed_on?->toDateString(),
                'confirmed' => $member->cga_contract_confirmed_by !== null,
                'ended_on' => $member->cga_contract_ended_on?->toDateString(),
            ],
            'adhesion_date' => $member->adhesion_date?->toDateString(),
            'validated_at' => $member->validated_at?->toIso8601ZuluString(),
            'entity_type' => $member->entity_type->value,
            'company_name' => $member->company_name,
            'sigle' => $member->sigle,
            'legal_form' => $member->legal_form->value,
            'legal_form_label' => $member->legal_form->label(),
            'share_capital' => $member->share_capital,
            'owner' => [
                'last_name' => $member->owner_last_name,
                'first_names' => $member->owner_first_names,
                'nationality' => $member->owner_nationality,
                'id_document_type' => $member->owner_id_document_type,
                'id_document_number' => $member->owner_id_document_number,
            ],
            'ncc' => $member->ncc,
            'rccm' => $member->rccm,
            'idu' => $member->idu,
            'regime' => $member->regime->value,
            'regime_label' => $member->regime->label(),
            'regime_since' => $member->regime_since->toDateString(),
            'rsi_since' => $member->rsi_since?->toDateString(),
            'tax_office' => $member->taxOffice === null ? null : ['id' => $member->taxOffice->id, 'code' => $member->taxOffice->code, 'name' => $member->taxOffice->name],
            'activity_code' => $member->activityCode === null ? null : ['id' => $member->activityCode->id, 'code' => $member->activityCode->code, 'label' => $member->activityCode->label],
            'activity_code_text' => $member->activity_code_text,
            'sector' => $member->sector === null ? null : ['id' => $member->sector->id, 'code' => $member->sector->code, 'name' => $member->sector->name, 'status' => $member->sector->status->value],
            'main_activity' => $member->main_activity,
            'address' => $member->address,
            'commune' => $member->commune === null ? null : ['id' => $member->commune->id, 'name' => $member->commune->name],
            'land_section' => $member->land_section,
            'land_parcel' => $member->land_parcel,
            'registration_date' => $member->registration_date?->toDateString(),
            'phone' => $member->phone,
            'whatsapp' => $member->whatsapp,
            'email' => $member->email,
            'signatory_name' => $member->signatory_name,
            'signatory_title' => $member->signatory_title,
            'employees_count' => $member->employees_count,
            'entry_fee_amount' => $member->entry_fee_amount,
            'fees_amount' => $member->fees_amount,
            'fees_periodicity' => $member->fees_periodicity->value,
            'pieces_status' => $member->pieces_status->value,
            'attestation_blocked' => $member->attestation_blocked,
            'reference_revenue' => $revenue instanceof MemberRevenue ? ['year' => $revenue->year, 'amount' => $revenue->amount, 'kind' => $revenue->kind->value, 'source' => $revenue->source->value] : null,
            // Ce qui manque encore pour valider l'adhésion (RG-ADH-02) : brouillon seulement.
            'missing_for_validation' => $member->status->value === 'draft' ? app(MembershipReadiness::class)->missing($member) : [],
            'created_at' => $member->created_at?->toIso8601ZuluString(),
            'can' => [
                'update' => Gate::allows('update', $member),
                'validate' => Gate::allows('validate', $member) && $member->status->value === 'draft',
                'delete' => Gate::allows('delete', $member) && $member->status->value === 'draft',
            ],
        ];
    }
}
