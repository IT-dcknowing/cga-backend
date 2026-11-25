<?php

namespace App\Domain\Concerns\Http\Requests;

use App\Domain\Concerns\Models\Concern;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /cga/concerns/indicators (gérant, son centre) et /network/concerns/indicators (CNCGA, réseau et par
 * CGA, filtre organization_id pour la fiche d'un CGA) : tableau des délais des demandes des adhérents
 * (F-PRE-04), sur une période facultative (dates de dépôt, bornes comprises).
 */
class ConcernIndicatorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows($this->routeIs('network.*') ? 'viewNetwork' : 'viewIndicators', Concern::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
        if ($this->routeIs('network.*')) {
            $rules['organization_id'] = ['nullable', 'string', 'ulid'];
        }

        return $rules;
    }

    public function from(): ?CarbonImmutable
    {
        $from = $this->validated('from');

        return is_string($from) ? CarbonImmutable::parse($from)->startOfDay() : null;
    }

    /** Lendemain de la dernière date, exclu. */
    public function until(): ?CarbonImmutable
    {
        $to = $this->validated('to');

        return is_string($to) ? CarbonImmutable::parse($to)->addDay()->startOfDay() : null;
    }

    public function organizationId(): ?string
    {
        $organization = $this->validated('organization_id');

        return is_string($organization) ? $organization : null;
    }
}
