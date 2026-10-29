<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Models\MemberObligation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/cga/obligation-planning : planning des échéances du portefeuille (F-OBL-06), filtré par
 * période d'échéance, obligation et avancement (à faire / faits). Sans période : du jour à l'horizon
 * de génération des échéances.
 */
class ObligationPlanningRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'obligation_type_id' => ['nullable', 'string', 'ulid'],
            'progress' => ['nullable', 'in:open,done'],
        ];
    }

    public function dueFrom(): ?CarbonImmutable
    {
        return $this->validatedDate('from');
    }

    public function dueTo(): ?CarbonImmutable
    {
        return $this->validatedDate('to');
    }

    public function typeId(): ?string
    {
        $id = $this->validated('obligation_type_id');

        return is_string($id) ? $id : null;
    }

    /**
     * @return 'open'|'done'|null
     */
    public function progress(): ?string
    {
        return match ($this->validated('progress')) {
            'open' => 'open',
            'done' => 'done',
            default => null,
        };
    }

    private function validatedDate(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
