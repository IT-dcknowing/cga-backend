<?php

namespace App\Domain\Reports\Http\Requests;

use App\Domain\Reports\Models\QuarterlyReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/cncga/quarterly-reports et …/{organization}/summary : année et trimestre consultés par le
 * CNCGA (RG-RPT-04).
 */
class NetworkReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewNetwork', QuarterlyReport::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['required', 'integer', 'between:1,4'],
        ];
    }

    public function year(): int
    {
        return (int) $this->validated('year');
    }

    public function quarter(): int
    {
        return (int) $this->validated('quarter');
    }
}
