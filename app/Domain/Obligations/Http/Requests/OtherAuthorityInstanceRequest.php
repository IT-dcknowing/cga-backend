<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Domain\Obligations\Models\MemberObligation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/members/{member}/obligation-instances : « + Autre administration » (F-OBL-04) —
 * administration, libellé facultatif, montant dû, échéance.
 */
class OtherAuthorityInstanceRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('manage', [MemberObligation::class, $this->member()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'authority' => ['required', 'string', 'max:150'],
            'label' => ['nullable', 'string', 'max:200'],
            'amount_due' => ['required', 'integer', 'min:0', 'max:'.config('cga.obligations.max_amount')],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function authority(): string
    {
        return (string) $this->validated('authority');
    }

    public function label(): ?string
    {
        $label = $this->validated('label');

        return is_string($label) && $label !== '' ? $label : null;
    }

    public function amountDue(): int
    {
        return (int) $this->validated('amount_due');
    }

    public function dueDate(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('due_date'));
    }
}
