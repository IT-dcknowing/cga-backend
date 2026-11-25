<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Obligations\Models\ObligationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/cga/obligation-types/suggestions : obligations applicables à un futur dossier, d'après son
 * régime, son effectif salarié et son secteur (RG-OBL-02, 03), pour l'assistant d'inscription.
 */
class ObligationSuggestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', ObligationType::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'regime' => ['required', Rule::enum(TaxRegime::class)],
            'employees_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'sector_id' => ['nullable', 'string', 'ulid', Rule::exists('sectors', 'id')],
        ];
    }
}
