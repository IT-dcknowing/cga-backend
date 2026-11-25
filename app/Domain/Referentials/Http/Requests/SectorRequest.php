<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\Sector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Création (POST) ou modification (PUT, PATCH) d'une valeur : Secteurs d'activité (F-REF-01, RG-REF-01).
 */
class SectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null ? Gate::allows('create', Sector::class) : Gate::allows('update', $current);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:10', 'alpha_dash', Rule::unique('sectors', 'code')->ignore($this->current())],
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * Valeur modifiée (route /sectors/{id}), absente à la création.
     */
    public function current(): ?Sector
    {
        $current = $this->route('sector');

        return $current instanceof Sector ? $current : null;
    }
}
