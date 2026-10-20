<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\NationalReference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Création (POST) ou modification (PUT, PATCH) d'une valeur : Références nationales annuelles (F-REF-04).
 */
class NationalReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null ? Gate::allows('create', NationalReference::class) : Gate::allows('update', $current);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'digits:4', Rule::unique('national_references', 'year')->ignore($this->current())],
            'domestic_tax_revenue' => ['nullable', 'integer', 'min:0'],
            'nominal_gdp' => ['nullable', 'integer', 'min:0'],
            'tax_revenue_source' => ['nullable', 'string', 'max:200'],
            'gdp_source' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * Valeur modifiée (route /national-references/{id}), absente à la création.
     */
    public function current(): ?NationalReference
    {
        $current = $this->route('nationalReference');

        return $current instanceof NationalReference ? $current : null;
    }
}
