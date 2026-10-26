<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\Commune;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Création (POST) ou modification (PUT, PATCH) d'une valeur : Communes (F-REF-02).
 */
class CommuneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null ? Gate::allows('create', Commune::class) : Gate::allows('update', $current);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('communes', 'name')->ignore($this->current())],
            'regional_directorate_id' => ['nullable', 'string', 'ulid', Rule::exists('regional_directorates', 'id')],
        ];
    }

    /**
     * Valeur modifiée (route /communes/{id}), absente à la création.
     */
    public function current(): ?Commune
    {
        $current = $this->route('commune');

        return $current instanceof Commune ? $current : null;
    }
}
