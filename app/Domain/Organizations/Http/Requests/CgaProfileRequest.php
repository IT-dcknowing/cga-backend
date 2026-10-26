<?php

namespace App\Domain\Organizations\Http\Requests;

use App\Domain\Referentials\Rules\TaxOfficeOfDirectorate;
use App\Support\Phones\PhoneNormalizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/cga/profile : complément de la fiche par le gérant (F-ORG-03). Le CDI appartient à la
 * direction régionale choisie ; l'abréviation de numérotation compte 2 à 6 lettres majuscules,
 * unique dans le réseau (RG-ORG-05).
 */
class CgaProfileRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $context->has() && Gate::allows('updateProfile', $context->organization());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones, TenantContext $context): array
    {
        $organizationId = $context->organizationId();

        return [
            'legal_form' => ['nullable', 'string', 'max:40'],
            'share_capital' => ['nullable', 'integer', 'min:0'],
            'ncc' => ['nullable', 'string', 'max:20'],
            'rccm' => ['nullable', 'string', 'max:50'],
            'agreement_number' => ['nullable', 'string', 'max:60', Rule::unique('organizations', 'agreement_number')->ignore($organizationId)],
            'agreement_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'regional_directorate_id' => ['nullable', 'required_with:tax_office_id', 'string', 'ulid', Rule::exists('regional_directorates', 'id')],
            'tax_office_id' => ['nullable', 'string', 'ulid', new TaxOfficeOfDirectorate],
            'said' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
            'commune_id' => ['nullable', 'string', 'ulid', Rule::exists('communes', 'id')],
            'phone_1' => ['required', 'string', 'max:30', $phones->rule()],
            'phone_2' => ['nullable', 'string', 'max:30', $phones->rule()],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'manager_name' => ['nullable', 'string', 'max:150'],
            'expert_name' => ['nullable', 'string', 'max:150'],
            'signatory_name' => ['nullable', 'string', 'max:150'],
            'signatory_title' => ['nullable', 'string', 'max:100'],
            'numbering_abbreviation' => ['nullable', 'string', 'regex:/^[A-Z]{2,6}$/', Rule::unique('organizations', 'numbering_abbreviation')->ignore($organizationId)],
            'history_text' => ['nullable', 'string', 'max:5000'],
            'previous_year_revenue' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
