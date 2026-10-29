<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Data\CenterObligationEntry;
use App\Domain\Obligations\Enums\CenterObligationKind;
use App\Domain\Obligations\Enums\CenterObligationStatus;
use App\Domain\Obligations\Models\CenterObligation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/center-obligations et PUT /api/v1/cga/center-obligations/{centerObligation} :
 * obligation propre du CGA (F-OBL-07) — libellé, nature, échéance, montant, statut, notes.
 */
class CenterObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', CenterObligation::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:200'],
            'kind' => ['required', Rule::enum(CenterObligationKind::class)],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:'.config('cga.obligations.max_amount')],
            'status' => ['required', Rule::enum(CenterObligationStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function entry(): CenterObligationEntry
    {
        $amount = $this->validated('amount');
        $notes = $this->validated('notes');

        return new CenterObligationEntry(
            label: (string) $this->validated('label'),
            kind: CenterObligationKind::from((string) $this->validated('kind')),
            dueDate: CarbonImmutable::parse((string) $this->validated('due_date')),
            amount: $amount === null ? null : (int) $amount,
            status: CenterObligationStatus::from((string) $this->validated('status')),
            notes: is_string($notes) && $notes !== '' ? $notes : null,
        );
    }
}
