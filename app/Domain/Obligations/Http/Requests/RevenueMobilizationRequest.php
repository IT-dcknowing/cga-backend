<?php

namespace App\Domain\Obligations\Http\Requests;

use App\Domain\Obligations\Models\MemberObligation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/cga/obligation-planning/revenue : mobilisation des recettes du portefeuille (onglet de
 * `/cga/echeances`) sur une période d'échéance. Sans période : du 1er janvier de l'année au jour.
 */
class RevenueMobilizationRequest extends FormRequest
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
        ];
    }

    public function dueFrom(CarbonImmutable $today): CarbonImmutable
    {
        $from = $this->validated('from');

        return is_string($from) ? CarbonImmutable::parse($from) : $today->startOfYear();
    }

    public function dueTo(CarbonImmutable $today): CarbonImmutable
    {
        $to = $this->validated('to');

        return is_string($to) ? CarbonImmutable::parse($to) : $today;
    }
}
