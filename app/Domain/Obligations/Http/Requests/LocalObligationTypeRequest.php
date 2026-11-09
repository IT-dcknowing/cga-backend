<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Enums\ObligationPeriodicity;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Obligations\Support\DueRule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/obligation-types et PUT /api/v1/cga/obligation-types/{obligationType} : obligation
 * locale du centre (RG-OBL-01), mêmes champs que le catalogue national ; code unique dans le centre.
 */
class LocalObligationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->obligationType();

        return $type === null ? Gate::allows('create', ObligationType::class) : Gate::allows('update', $type);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantContext $context): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('obligation_types', 'code')
                ->where('organization_id', $context->organizationId())
                ->where('catalog_version', (string) config('cga.obligations.local_catalog_version'))
                ->ignore($this->obligationType()?->id)],
            'label' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::enum(ObligationCategory::class)],
            'authority' => ['required', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'max:50'],
            'kind' => ['required', Rule::enum(ObligationKind::class)],
            'periodicity' => ['required', Rule::enum(ObligationPeriodicity::class)],
            ...DueRule::rules('due_rule'),
            'applicability' => ['required', 'array'],
            'applicability.regimes' => ['present', 'array'],
            'applicability.regimes.*' => ['distinct', Rule::enum(TaxRegime::class)],
            'applicability.min_employees' => ['nullable', 'integer', 'min:0'],
            'applicability.sectors' => ['present', 'array'],
            'applicability.sectors.*' => ['distinct', 'string', 'max:10', Rule::exists('sectors', 'code')],
            'tax_nature' => ['nullable', Rule::enum(TaxNature::class)],
            'has_abatement' => ['sometimes', 'boolean'],
            'creates_payment_on_done' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'legal_basis' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function obligationType(): ?ObligationType
    {
        $type = $this->route('obligationType');

        return $type instanceof ObligationType ? $type : null;
    }
}
