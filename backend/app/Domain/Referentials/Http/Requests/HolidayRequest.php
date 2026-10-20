<?php

namespace App\Domain\Referentials\Http\Requests;

use App\Domain\Referentials\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Création (POST) ou modification (PUT, PATCH) d'une valeur : Jours fériés (RG-OBL-06).
 */
class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $current = $this->current();

        return $current === null ? Gate::allows('create', Holiday::class) : Gate::allows('update', $current);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->ignore($this->current())],
            'label' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * Valeur modifiée (route /holidays/{id}), absente à la création.
     */
    public function current(): ?Holiday
    {
        $current = $this->route('holiday');

        return $current instanceof Holiday ? $current : null;
    }
}
