<?php

namespace App\Domain\Statistics\Http\Requests;

use App\Domain\Statistics\Support\StatPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sélecteur de période (F-DSH-04) : period = day, month (par défaut), year ou custom ; date de référence (par
 * défaut aujourd'hui) ; from et to pour une période personnalisée, au plus cga.statistics.max_period_days jours.
 * Aucune date future : les agrégats s'arrêtent au jour même.
 */
abstract class StatPeriodRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $today = CarbonImmutable::today()->toDateString();

        return [
            'period' => ['nullable', 'string', Rule::in(StatPeriod::TYPES)],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'from' => ['nullable', 'required_if:period,'.StatPeriod::CUSTOM, 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'to' => ['nullable', 'required_if:period,'.StatPeriod::CUSTOM, 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @throws ValidationException période personnalisée trop longue
     */
    protected function passedValidation(): void
    {
        $period = $this->period();
        $max = (int) config('cga.statistics.max_period_days');
        if ($period->type === StatPeriod::CUSTOM && $period->from->diffInDays($period->to) + 1 > $max) {
            throw ValidationException::withMessages(['to' => __('statistics.period_too_long', ['days' => $max])]);
        }
    }

    public function period(): StatPeriod
    {
        $date = $this->validated('date');
        $from = $this->validated('from');
        $to = $this->validated('to');

        return StatPeriod::of(
            (string) ($this->validated('period') ?? StatPeriod::MONTH),
            $date === null ? CarbonImmutable::today() : CarbonImmutable::parse((string) $date),
            $from === null ? null : CarbonImmutable::parse((string) $from),
            $to === null ? null : CarbonImmutable::parse((string) $to),
        );
    }
}
