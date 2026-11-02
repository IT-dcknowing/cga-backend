<?php

namespace App\Domain\Reports\Http\Requests;

use App\Domain\Reports\Models\QuarterlyReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/quarterly-reports/{quarterlyReport}/correct : correction après envoi (F-RPT-06) —
 * motif de la nouvelle version.
 */
class CorrectReportRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
