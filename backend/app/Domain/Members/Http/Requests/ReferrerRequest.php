<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Models\BusinessReferrer;
use App\Support\Phones\PhoneNormalizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/referrers et PUT /api/v1/cga/referrers/{referrer} : fiche d'un apporteur d'affaires
 * (RG-PTF-06) — libellé unique dans le centre (proposé s'il est vide), nom, contact, conditions, état.
 */
class ReferrerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $referrer = $this->referrer();

        return $referrer === null ? Gate::allows('create', BusinessReferrer::class) : Gate::allows('update', $referrer);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones, TenantContext $context): array
    {
        return [
            'label' => ['nullable', 'string', 'max:40', Rule::unique('business_referrers', 'label')->where('organization_id', $context->organizationId())->ignore($this->referrer()?->id)],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', $phones->rule()],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return array_filter($this->validated(), static fn (mixed $value, string $key): bool => $key !== 'active' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    private function referrer(): ?BusinessReferrer
    {
        $referrer = $this->route('referrer');

        return $referrer instanceof BusinessReferrer ? $referrer : null;
    }
}
