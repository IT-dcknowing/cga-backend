<?php

namespace App\Domain\Reports\Http\Requests;

use App\Domain\Reports\Actions\CreateQuarterlyReport;
use App\Domain\Reports\Models\QuarterlyReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/cga/quarterly-reports : « Préparer le rapport T{n} » (F-RPT-02) — année et trimestre
 * choisis dans une liste : le trimestre en cours ou le précédent.
 */
class CreateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('prepare', QuarterlyReport::class);
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

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            // Données déjà contrôlées par les règles ci-dessus (aucune erreur à ce stade).
            $data = $validator->getData();
            $period = ['year' => (int) $data['year'], 'quarter' => (int) $data['quarter']];
            if (! in_array($period, CreateQuarterlyReport::creatable(CarbonImmutable::today()), true)) {
                $validator->errors()->add('quarter', __('report.quarter_not_open'));
            }
        }];
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
