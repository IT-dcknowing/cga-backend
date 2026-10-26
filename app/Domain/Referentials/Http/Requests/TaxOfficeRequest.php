<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\TaxOffice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Création (POST) ou modification (PUT, PATCH) d'une valeur : Centres des impôts (F-REF-02).
 */
class TaxOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null ? Gate::allows('create', TaxOffice::class) : Gate::allows('update', $current);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'regional_directorate_id' => ['required', 'string', 'ulid', Rule::exists('regional_directorates', 'id')],
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('tax_offices', 'code')->ignore($this->current())],
            'name' => ['required', 'string', 'max:150'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Valeur modifiée (route /tax-offices/{id}), absente à la création.
     */
    public function current(): ?TaxOffice
    {
        $current = $this->route('taxOffice');

        return $current instanceof TaxOffice ? $current : null;
    }
}
