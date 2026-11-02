<?php

namespace App\Domain\Reports\Http\Requests;

use App\Domain\Reports\Enums\CgaPersonKind;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/cga/quarterly-reports/{quarterlyReport} : enregistrement automatique (F-RPT-02) — un ou
 * plusieurs champs des sections 4 et 6 (config reports.content), impôts du trimestre des dirigeants
 * actifs, analyse qualitative, date du rapport et Direction régionale. Seules les clés déclarées sont
 * acceptées ; un texte vide vaut « non renseigné ».
 */
class SaveReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('prepare', QuarterlyReport::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantContext $context): array
    {
        $text = ['nullable', 'string', 'max:'.(int) config('reports.text_max_length')];
        $integer = ['nullable', 'integer', 'min:0', 'max:'.(int) config('reports.integer_max')];
        $amount = ['nullable', 'integer', 'min:0', 'max:'.(int) config('cga.obligations.max_amount')];
        $fields = $this->contentFields();
        $rules = [
            'content' => ['sometimes', 'array:'.implode(',', array_keys($fields))],
            'qualitative_analysis' => ['sometimes', 'array:'.implode(',', array_keys((array) config('reports.qualitative_analysis')))],
            'qualitative_analysis.*' => $text,
            'people_taxes' => ['sometimes', 'array', 'max:100'],
            'people_taxes.*' => ['array:person_id,'.implode(',', (array) config('reports.people_taxes'))],
            'people_taxes.*.person_id' => ['required', 'string', 'size:26', 'distinct', Rule::exists('cga_people', 'id')
                ->where('organization_id', $context->organizationId())
                ->where('kind', CgaPersonKind::Director->value)
                ->where('active', true)],
            'report_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'regional_directorate_label' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
        foreach ((array) config('reports.people_taxes') as $tax) {
            $rules["people_taxes.*.{$tax}"] = $amount;
        }
        foreach ($fields as $key => $type) {
            $rules["content.{$key}"] = $type === 'integer' ? $integer : $text;
        }

        return $rules;
    }

    /**
     * @return array{content?: array<string, mixed>, qualitative_analysis?: array<string, string|null>, people_taxes?: list<array{person_id: string, its: int|null, igr: int|null, cnps: int|null}>, report_date?: string|null, regional_directorate_label?: string|null}
     */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes = [];
        if (array_key_exists('content', $validated)) {
            $changes['content'] = array_map(self::clean(...), (array) $validated['content']);
        }
        if (array_key_exists('qualitative_analysis', $validated)) {
            $changes['qualitative_analysis'] = array_map(self::clean(...), (array) $validated['qualitative_analysis']);
        }
        if (array_key_exists('people_taxes', $validated)) {
            $changes['people_taxes'] = array_values(array_map(static function (array $row): array {
                $taxes = ['person_id' => (string) $row['person_id']];
                foreach ((array) config('reports.people_taxes') as $tax) {
                    $taxes[$tax] = isset($row[$tax]) ? (int) $row[$tax] : null;
                }

                return $taxes;
            }, (array) $validated['people_taxes']));
        }
        foreach (['report_date', 'regional_directorate_label'] as $key) {
            if (array_key_exists($key, $validated)) {
                $changes[$key] = self::clean($validated[$key]);
            }
        }

        /** @var array{content?: array<string, mixed>, qualitative_analysis?: array<string, string|null>, people_taxes?: list<array{person_id: string, its: int|null, igr: int|null, cnps: int|null}>, report_date?: string|null, regional_directorate_label?: string|null} */
        return $changes;
    }

    /**
     * @return array<string, string> champ => type
     */
    private function contentFields(): array
    {
        $fields = [];
        foreach ((array) config('reports.content') as $section) {
            foreach ((array) $section as $key => $definition) {
                $fields[(string) $key] = (string) $definition['type'];
            }
        }

        return $fields;
    }

    private static function clean(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return is_numeric($value) ? (int) $value : $value;
    }
}
