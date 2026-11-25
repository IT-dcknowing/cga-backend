<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Models\MemberObligation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/cga/obligation-planning/members : adhérents concernés par une ligne du planning (panneau
 * latéral, F-OBL-06) — échéance, nature, obligation du catalogue (ou libellé d'une ligne hors catalogue).
 */
class PlanningMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewPlanning', MemberObligation::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'due_date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', Rule::enum(InstanceKind::class)],
            'obligation_type_id' => ['nullable', 'string', 'ulid'],
            'label' => ['required', 'string', 'max:200'],
        ];
    }

    public function dueDate(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('due_date'));
    }

    public function kind(): InstanceKind
    {
        return InstanceKind::from((string) $this->validated('kind'));
    }

    public function typeId(): ?string
    {
        $id = $this->validated('obligation_type_id');

        return is_string($id) ? $id : null;
    }

    public function label(): string
    {
        return (string) $this->validated('label');
    }
}
