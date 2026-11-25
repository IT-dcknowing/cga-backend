<?php

namespace App\Domain\Reports\Http\Requests;

use App\Domain\Reports\Models\CgaPerson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /api/v1/cga/permanent-profile/presentation : présentation et historique du CGA, chiffre
 * d'affaires N-1 du centre (fiche permanente, F-RPT-01 ; section 1 du rapport). La Direction régionale
 * et le SAID restent sur la fiche du centre (F-ORG-03).
 */
class PresentationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', CgaPerson::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'history_text' => ['present', 'nullable', 'string', 'max:5000'],
            'previous_year_revenue' => ['present', 'nullable', 'integer', 'min:0', 'max:'.config('cga.obligations.max_amount')],
        ];
    }

    /**
     * @return array{history_text: string|null, previous_year_revenue: int|null}
     */
    public function presentation(): array
    {
        $history = $this->validated('history_text');
        $revenue = $this->validated('previous_year_revenue');

        return [
            'history_text' => is_string($history) && trim($history) !== '' ? trim($history) : null,
            'previous_year_revenue' => $revenue === null ? null : (int) $revenue,
        ];
    }
}
