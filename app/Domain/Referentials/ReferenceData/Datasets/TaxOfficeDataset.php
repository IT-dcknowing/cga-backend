<?php

namespace App\Domain\Referentials\ReferenceData\Datasets;

use App\Domain\Imports\Support\CellValue;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Referentials\ReferenceData\ParsedRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Centres des impôts (F-REF-02) : un par code, rattaché à une direction régionale existante
 * (importer les DR d'abord).
 */
class TaxOfficeDataset extends ReferentialDataset
{
    /**
     * Directions régionales par code.
     *
     * @var array<string, string>|null
     */
    private ?array $directorates = null;

    public function columns(): array
    {
        return [
            'code' => ['label' => 'Code *', 'help' => __('reference_data.help.code'), 'aliases' => ['Code']],
            'name' => ['label' => 'Nom *', 'help' => __('reference_data.help.name'), 'aliases' => ['Nom', 'CDI', 'Centre des impôts', 'Libellé']],
            'regional_directorate' => ['label' => 'Code de la DR *', 'help' => __('reference_data.help.directorate_code'), 'aliases' => ['DR', 'Direction régionale', 'Code DR']],
            'active' => ['label' => 'Actif', 'help' => __('reference_data.help.active'), 'aliases' => ['Active']],
        ];
    }

    public function requiredColumns(): array
    {
        return ['code', 'name', 'regional_directorate'];
    }

    public function labelColumn(): string
    {
        return 'name';
    }

    public function fileName(): string
    {
        return 'centres-des-impots';
    }

    /**
     * @return Builder<TaxOffice>
     */
    public function query(): Builder
    {
        return TaxOffice::query();
    }

    public function parse(int $number, array $values): ParsedRow
    {
        $code = CellValue::text($values['code'] ?? null);
        $name = CellValue::text($values['name'] ?? null);
        $directorate = CellValue::text($values['regional_directorate'] ?? null);
        [$active, $readable] = Fields::boolean($values['active'] ?? null);
        $directorateId = $directorate === null ? null : $this->directorateId($directorate);

        $errors = Fields::errors(['Code' => [$code, 20, true], 'Nom' => [$name, 150, true], 'Code de la DR' => [$directorate, 20, true]]);
        if ($code !== null && preg_match('/^[A-Za-z0-9_-]+$/', $code) !== 1) {
            $errors[] = __('reference_data.invalid_code');
        }
        if ($directorate !== null && $directorateId === null) {
            $errors[] = __('reference_data.unknown_directorate', ['value' => $directorate]);
        }
        if (! $readable) {
            $errors[] = __('reference_data.invalid_boolean', ['field' => 'Actif']);
        }

        return new ParsedRow($number, $code === null ? null : Str::upper($code), $name, Fields::provided([
            'code' => $code === null ? null : Str::upper($code),
            'name' => $name,
            'regional_directorate_id' => $directorateId,
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
        return new TaxOffice;
    }

    private function directorateId(string $code): ?string
    {
        $this->directorates ??= RegionalDirectorate::query()->get(['id', 'code'])
            ->mapWithKeys(static fn (RegionalDirectorate $dr): array => [Str::upper($dr->code) => $dr->id])
            ->all();

        return $this->directorates[Str::upper($code)] ?? null;
    }
}
