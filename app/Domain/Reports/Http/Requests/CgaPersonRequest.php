<?php

namespace App\Domain\Reports\Http\Requests;

use App\Domain\Reports\Data\CgaPersonEntry;
use App\Domain\Reports\Enums\CgaPersonKind;
use App\Domain\Reports\Models\CgaPerson;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/permanent-profile/people et PUT …/people/{cgaPerson} : dirigeant, salarié permanent
 * ou occasionnel de la fiche permanente (F-RPT-01). Le départ se marque par active = false et une date
 * de fin.
 */
class CgaPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', CgaPerson::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(CgaPersonKind::class)],
            'full_name' => ['required', 'string', 'max:200'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'profession' => ['nullable', 'string', 'max:150'],
            'diplomas' => ['nullable', 'string', 'max:255'],
            'function_in_cga' => ['nullable', 'string', 'max:150'],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'practice_structure' => ['nullable', 'string', 'max:150'],
            'practice_function' => ['nullable', 'string', 'max:150'],
            'practice_years' => ['nullable', 'integer', 'min:0', 'max:80'],
            'cnps_declared' => ['nullable', 'boolean'],
            'active' => ['required', 'boolean'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date', 'required_if:active,false'],
        ];
    }

    public function entry(): CgaPersonEntry
    {
        $text = fn (string $field): ?string => is_string($value = $this->validated($field)) && trim($value) !== '' ? trim($value) : null;
        $date = fn (string $field): ?CarbonImmutable => is_string($value = $this->validated($field)) ? CarbonImmutable::parse($value) : null;
        $years = $this->validated('practice_years');
        $cnps = $this->validated('cnps_declared');

        return new CgaPersonEntry(
            kind: CgaPersonKind::from((string) $this->validated('kind')),
            fullName: trim((string) $this->validated('full_name')),
            nationality: $text('nationality'),
            profession: $text('profession'),
            diplomas: $text('diplomas'),
            functionInCga: $text('function_in_cga'),
            startDate: $date('start_date'),
            practiceStructure: $text('practice_structure'),
            practiceFunction: $text('practice_function'),
            practiceYears: $years === null ? null : (int) $years,
            cnpsDeclared: $cnps === null ? null : filter_var($cnps, FILTER_VALIDATE_BOOLEAN),
            active: filter_var($this->validated('active'), FILTER_VALIDATE_BOOLEAN),
            endDate: $date('end_date'),
        );
    }
}
