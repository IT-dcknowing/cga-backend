<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Models\MemberObligation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/cga/members/{member}/obligation-instances : échéances du dossier (onglet Obligations,
 * F-OBL-04), filtrées par bloc et par période d'échéance.
 */
class ListObligationInstancesRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', [MemberObligation::class, $this->member()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', Rule::enum(ObligationCategory::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function category(): ?ObligationCategory
    {
        $category = $this->validated('category');

        return is_string($category) ? ObligationCategory::from($category) : null;
    }

    public function dueFrom(): ?CarbonImmutable
    {
        return $this->validatedDate('from');
    }

    public function dueTo(): ?CarbonImmutable
    {
        return $this->validatedDate('to');
    }

    private function validatedDate(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
