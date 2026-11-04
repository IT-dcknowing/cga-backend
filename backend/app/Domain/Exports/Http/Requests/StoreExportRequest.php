<?php

namespace App\Domain\Exports\Http\Requests;

use App\Domain\Exports\Enums\ExportType;
use App\Domain\Exports\Models\Export;
use App\Domain\Statistics\Support\StatPeriod;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /{network|cga}/exports : liste à exporter (celles de l'espace, F-EXP-01), période facultative (jour, mois,
 * année ou personnalisée, au plus cga.statistics.max_period_days jours ; sans période, toute la liste ou l'année
 * en cours selon la liste) et filtres propres à la liste, clés inconnues refusées. Le droit de la liste est
 * vérifié une fois la liste validée (403).
 */
class StoreExportRequest extends FormRequest
{
    /** @var array<string, mixed> filtres validés selon la liste */
    private array $filters = [];

    public function authorize(): bool
    {
        return Gate::allows('viewAny', Export::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $space = app(TenantContext::class)->organizationType();

        return [
            'type' => ['required', 'string', Rule::in(array_map(static fn (ExportType $type): string => $type->value, ExportType::in($space)))],
            'period' => ['nullable', 'string', Rule::in(StatPeriod::TYPES)],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'from' => ['nullable', 'required_if:period,'.StatPeriod::CUSTOM, 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'to' => ['nullable', 'required_if:period,'.StatPeriod::CUSTOM, 'date_format:Y-m-d', 'after_or_equal:from'],
            'filters' => ['nullable', 'array'],
        ];
    }

    /**
     * Droit de la liste (403), filtres validés selon la liste (clés inconnues refusées, 422), période au plus
     * cga.statistics.max_period_days jours.
     *
     * @throws ValidationException filtres invalides, période personnalisée trop longue
     */
    protected function passedValidation(): void
    {
        $type = $this->exportType();
        Gate::authorize('request', [Export::class, $type]);

        $rules = app($type->exporter())->filterRules();
        $keys = array_values(array_filter(array_keys($rules), static fn (string $key): bool => ! str_contains($key, '.')));
        $prefixed = ['filters' => $keys === [] ? ['prohibited'] : ['nullable', 'array:'.implode(',', $keys)]];
        foreach ($rules as $key => $rule) {
            $prefixed["filters.{$key}"] = $rule;
        }
        $this->filters = (array) (Validator::make(['filters' => $this->validated('filters')], $prefixed)->validate()['filters'] ?? []);

        $period = $this->period();
        $max = (int) config('cga.statistics.max_period_days');
        if ($period !== null && $period->from->diffInDays($period->to) + 1 > $max) {
            throw ValidationException::withMessages(['to' => __('statistics.period_too_long', ['days' => $max])]);
        }
    }

    public function exportType(): ExportType
    {
        return ExportType::from((string) $this->validated('type'));
    }

    public function period(): ?StatPeriod
    {
        $type = $this->validated('period');
        if ($type === null) {
            return null;
        }
        $from = $this->validated('from');
        $to = $this->validated('to');

        return StatPeriod::of(
            (string) $type,
            $this->validated('date') === null ? CarbonImmutable::today() : CarbonImmutable::parse((string) $this->validated('date')),
            $from === null ? null : CarbonImmutable::parse((string) $from),
            $to === null ? null : CarbonImmutable::parse((string) $to),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter($this->filters, static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
