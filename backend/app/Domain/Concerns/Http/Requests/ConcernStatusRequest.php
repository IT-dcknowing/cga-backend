<?php

namespace App\Domain\Concerns\Http\Requests;

use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Http\Requests\Concerns\RouteConcern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /cga/concerns/{concern}/status et /network/concerns/{concernId}/status : changement de statut par
 * qui traite la demande (F-PRE-02) — En examen, Traitée, ou Rejetée avec son motif.
 */
class ConcernStatusRequest extends FormRequest
{
    use RouteConcern;

    public function authorize(): bool
    {
        return Gate::allows('handle', $this->concern());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([ConcernStatus::InReview->value, ConcernStatus::Resolved->value, ConcernStatus::Rejected->value])],
            'reason' => ['nullable', 'required_if:status,'.ConcernStatus::Rejected->value, 'string', 'max:'.(int) config('cga.concerns.reason_max_length')],
        ];
    }

    public function status(): ConcernStatus
    {
        return ConcernStatus::from((string) $this->validated('status'));
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
