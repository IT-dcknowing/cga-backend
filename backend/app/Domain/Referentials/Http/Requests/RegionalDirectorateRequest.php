<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\RegionalDirectorate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Création (POST) ou modification (PUT, PATCH) d'une valeur : Directions régionales (F-REF-02).
 */
class RegionalDirectorateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null ? Gate::allows('create', RegionalDirectorate::class) : Gate::allows('update', $current);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('regional_directorates', 'code')->ignore($this->current())],
            'name' => ['required', 'string', 'max:150'],
            'geo_key' => ['nullable', 'string', 'max:50'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Valeur modifiée (route /regional-directorates/{id}), absente à la création.
     */
    public function current(): ?RegionalDirectorate
    {
        $current = $this->route('regionalDirectorate');

        return $current instanceof RegionalDirectorate ? $current : null;
    }
}
