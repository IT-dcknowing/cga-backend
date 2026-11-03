<?php

namespace App\Domain\Dues\Http\Requests;

use App\Domain\Dues\Enums\DuesStatus;
use App\Domain\Dues\Models\NetworkDue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/network/dues : année et statut du tableau des cotisations (F-COT-02).
 */
class NetworkDuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewNetwork', NetworkDue::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Une année (tableau du CNCGA), ou un CGA (onglet Cotisations de sa fiche), toutes années.
            'year' => ['required_without:cga', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'cga' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(DuesStatus::class)],
        ];
    }

    public function year(): ?int
    {
        return $this->validated('year') === null ? null : (int) $this->validated('year');
    }

    public function cga(): ?string
    {
        return $this->validated('cga') === null ? null : (string) $this->validated('cga');
    }

    public function status(): ?string
    {
        $status = $this->validated('status');

        return is_string($status) ? $status : null;
    }
}
