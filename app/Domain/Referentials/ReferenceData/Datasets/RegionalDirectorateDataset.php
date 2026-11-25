<?php

namespace App\Domain\Referentials\ReferenceData\Datasets;

use App\Domain\Imports\Support\CellValue;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\ReferenceData\ParsedRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Directions régionales (F-REF-02) : une par code.
 */
class RegionalDirectorateDataset extends ReferentialDataset
{
    public function columns(): array
    {
        return [
            'code' => ['label' => 'Code *', 'help' => __('reference_data.help.code'), 'aliases' => ['Code']],
            'name' => ['label' => 'Nom *', 'help' => __('reference_data.help.name'), 'aliases' => ['Nom', 'Direction régionale', 'Libellé']],
            'geo_key' => ['label' => 'Zone de la carte', 'help' => __('reference_data.help.geo_key'), 'aliases' => ['Carte']],
            'active' => ['label' => 'Active', 'help' => __('reference_data.help.active'), 'aliases' => []],
        ];
    }

    public function requiredColumns(): array
    {
        return ['code', 'name'];
    }

    public function labelColumn(): string
    {
        return 'name';
    }

    public function fileName(): string
    {
        return 'directions-regionales';
    }

    /**
     * @return Builder<RegionalDirectorate>
     */
    public function query(): Builder
    {
        return RegionalDirectorate::query();
    }

    public function parse(int $number, array $values): ParsedRow
    {
        $code = CellValue::text($values['code'] ?? null);
        $name = CellValue::text($values['name'] ?? null);
        $geoKey = CellValue::text($values['geo_key'] ?? null);
        [$active, $readable] = Fields::boolean($values['active'] ?? null);

        $errors = Fields::errors([
            'Code' => [$code, 20, true],
            'Nom' => [$name, 150, true],
            'Zone de la carte' => [$geoKey, 50, false],
        ]);
        if ($code !== null && preg_match('/^[A-Za-z0-9_-]+$/', $code) !== 1) {
            $errors[] = __('reference_data.invalid_code');
        }
        if (! $readable) {
            $errors[] = __('reference_data.invalid_boolean', ['field' => 'Active']);
        }

        return new ParsedRow($number, $code === null ? null : Str::upper($code), $name, Fields::provided([
            'code' => $code === null ? null : Str::upper($code),
            'name' => $name,
            'geo_key' => $geoKey,
            'active' => $active,
        ]), $errors);
    }

    public function labelOf(Model $model): string
    {
        return (string) $model->getAttribute('code').' — '.$model->getAttribute('name');
    }

    protected function keyOf(Model $model): string
    {
        return Str::upper((string) $model->getAttribute('code'));
    }

    protected function deactivatable(): bool
    {
        return true;
    }

    protected function newModel(): Model
    {
        return new RegionalDirectorate;
    }
}
