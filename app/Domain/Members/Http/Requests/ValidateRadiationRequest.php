<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Enums\RadiationOutcome;
use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/radiation/validate : « Valider la radiation » (RG-ADH-17) — issue
 * (clore le dossier ou le basculer dans le portefeuille Cabinet) et référence de validation.
 */
class ValidateRadiationRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('validateRadiation', $this->member());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(RadiationOutcome::class)],
            'validation_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function outcome(): RadiationOutcome
    {
        return RadiationOutcome::from((string) $this->validated('outcome'));
    }

    public function reference(): ?string
    {
        $reference = $this->validated('validation_reference');

        return is_string($reference) ? $reference : null;
    }
}
