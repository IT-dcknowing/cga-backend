<?php

namespace App\Domain\Members\Http\Resources;

use App\Domain\Members\Actions\CancelRadiation;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Members\Support\MembershipReadiness;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Dossier d'un adhérent (F-ADH-03) : portefeuille, statut, numéros, contrat CGA, identité, fiscalité,
 * localisation, contacts, honoraires, état des pièces, chiffre d'affaires de référence et actions
 * permises, alertes et radiation en vigueur. L'apporteur n'est montré qu'avec le droit
 * members.referrers.view (RG-PTF-06).
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
            'alerts' => $this->alerts($member),
            'radiation' => $this->radiation($member),
            'reference_revenue' => $revenue instanceof MemberRevenue ? ['year' => $revenue->year, 'amount' => $revenue->amount, 'kind' => $revenue->kind->value, 'source' => $revenue->source->value] : null,
            // Ce qui manque encore pour valider l'adhésion (RG-ADH-02) : brouillon seulement.
            'missing_for_validation' => $member->status->value === 'draft' ? app(MembershipReadiness::class)->missing($member) : [],
            'created_at' => $member->created_at?->toIso8601ZuluString(),
            'can' => [
                'update' => Gate::allows('update', $member),
                'validate' => Gate::allows('validate', $member) && $member->status->value === 'draft',
                'delete' => Gate::allows('delete', $member) && $member->status->value === 'draft',
                'change_regime' => Gate::allows('changeRegime', $member) && in_array($member->status, [MemberStatus::Active, MemberStatus::RadiationPending], true),
                'request_radiation' => Gate::allows('requestRadiation', $member) && $member->portfolio === Portfolio::Cga && $member->status === MemberStatus::Active,
                'move_to_cga' => Gate::allows('moveToCga', $member) && $member->portfolio === Portfolio::Cabinet && $member->status === MemberStatus::Active && ! $member->regime->isReal(),
            ],
        ];
    }

    /**
     * Radiation en vigueur : demandée, ou validée tant que le dossier n'est pas revenu au portefeuille
     * CGA après une bascule vers le Cabinet.
     */
    private function radiation(Member $member): ?RadiationResource
    {
        $radiation = $member->relationLoaded('currentRadiation') ? $member->currentRadiation : null;

        return $radiation !== null && app(CancelRadiation::class)->inForce($member, $radiation) ? new RadiationResource($radiation, $member) : null;
    }

    /**
     * Bandeaux d'alerte de la fiche : « Radiation requise — reclassement au régime réel » tant qu'un
     * dossier actif du portefeuille CGA reste au régime réel sans radiation demandée (RG-ADH-08), en
     * retard passé rsi.radiation_deadline_days (RG-ADH-09). Une alerte ne bloque rien (CR-04).
     *
     * @return list<array{code: string, label: string, since: string, days: int, overdue: bool}>
     */
    private function alerts(Member $member): array
    {
        if ($member->portfolio !== Portfolio::Cga || $member->status !== MemberStatus::Active || $member->rsi_since === null) {
            return [];
        }
        $days = max(0, (int) $member->rsi_since->diffInDays(today()));
        $deadline = (int) app(SettingsService::class)->get('rsi.radiation_deadline_days', $member->organization_id);

        return [[
            'code' => 'radiation_required',
            'label' => __('members.alerts.radiation_required'),
            'since' => $member->rsi_since->toDateString(),
            'days' => $days,
            'overdue' => $days >= $deadline,
        ]];
    }
}
