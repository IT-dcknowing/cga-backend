<?php

namespace App\Domain\Reports\Http\Requests;

use App\Domain\Reports\Enums\TransmissionMode;
use App\Domain\Reports\Models\QuarterlyReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/quarterly-reports/{quarterlyReport}/transmit : transmission faite par le CGA au SP-CGA
 * (F-RPT-04) — date (passée ou du jour), mode, référence.
 */
class TransmitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('transmit', QuarterlyReport::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'transmitted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'mode' => ['required', Rule::enum(TransmissionMode::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function transmittedOn(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('transmitted_on'));
    }

    public function mode(): TransmissionMode
    {
        return TransmissionMode::from((string) $this->validated('mode'));
    }

    public function reference(): ?string
    {
        $reference = $this->validated('reference');

        return is_string($reference) && trim($reference) !== '' ? trim($reference) : null;
    }
}
