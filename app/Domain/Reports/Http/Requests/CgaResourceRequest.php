<?php

namespace App\Domain\Reports\Http\Requests;

use App\Domain\Reports\Models\CgaPerson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /api/v1/cga/permanent-profile/resources/{category} : moyens matériels d'une catégorie de la fiche
 * permanente (F-RPT-01) — quantité, description, besoins.
 */
class CgaResourceRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'need' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }

    public function text(string $field): ?string
    {
        $value = $this->validated($field);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
