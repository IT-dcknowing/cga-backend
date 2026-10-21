<?php

namespace App\Domain\Referentials\ReferenceData\Datasets;

use App\Domain\Imports\Support\CellValue;
use App\Domain\Referentials\Models\NationalReference;
use App\Domain\Referentials\ReferenceData\ParsedRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Références nationales (F-REF-04) : une par année — recettes fiscales intérieures et PIB nominal,
 * en francs CFA, avec leurs sources.
 */
class NationalReferenceDataset extends ReferentialDataset
{
    public function columns(): array
    {
        return [
            'year' => ['label' => 'Année *', 'help' => __('reference_data.help.year'), 'aliases' => ['Année', 'Annee', 'Exercice']],
            'domestic_tax_revenue' => ['label' => 'Recettes fiscales intérieures', 'help' => __('reference_data.help.amount'), 'aliases' => ['Recettes fiscales']],
            'nominal_gdp' => ['label' => 'PIB nominal', 'help' => __('reference_data.help.amount'), 'aliases' => ['PIB']],
            'tax_revenue_source' => ['label' => 'Source des recettes', 'help' => __('reference_data.help.source'), 'aliases' => []],
            'gdp_source' => ['label' => 'Source du PIB', 'help' => __('reference_data.help.source'), 'aliases' => []],
        ];
    }

    public function requiredColumns(): array
    {
        return ['year'];
    }

    public function labelColumn(): string
    {
        return 'year';
    }

    public function fileName(): string
    {
        return 'references-nationales';
    }

    /**
     * @return Builder<NationalReference>
     */
    public function query(): Builder
    {
        return NationalReference::query();
    }

    public function parse(int $number, array $values): ParsedRow
    {
        $year = CellValue::text($values['year'] ?? null);
        $errors = $year === null ? [__('reference_data.required', ['field' => 'Année'])] : [];
        if ($year !== null && preg_match('/^(19|20)\d{2}$/', $year) !== 1) {
            $errors[] = __('reference_data.invalid_year', ['value' => $year]);
        }

        $amounts = [];
        foreach (['domestic_tax_revenue' => 'Recettes fiscales intérieures', 'nominal_gdp' => 'PIB nominal'] as $field => $label) {
            $text = CellValue::text($values[$field] ?? null);
            $amount = $text === null ? null : preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $text);
            if ($amount !== null && preg_match('/^\d+$/', (string) $amount) !== 1) {
                $errors[] = __('reference_data.invalid_amount', ['field' => $label]);
                $amount = null;
            }
            $amounts[$field] = $amount === null ? null : (int) $amount;
        }

        $taxSource = CellValue::text($values['tax_revenue_source'] ?? null);
        $gdpSource = CellValue::text($values['gdp_source'] ?? null);
        $errors = [...$errors, ...Fields::errors(['Source des recettes' => [$taxSource, 255, false], 'Source du PIB' => [$gdpSource, 255, false]])];

        return new ParsedRow($number, $year, $year, Fields::provided([
            'year' => $year === null ? null : (int) $year,
            ...$amounts,
            'tax_revenue_source' => $taxSource,
            'gdp_source' => $gdpSource,
        ]), $errors);
    }

    public function labelOf(Model $model): string
    {
        return (string) $model->getAttribute('year');
    }

    protected function keyOf(Model $model): string
    {
        return (string) $model->getAttribute('year');
    }

    protected function deactivatable(): bool
    {
        return false;
    }

    protected function newModel(): Model
    {
        return new NationalReference;
    }
}
