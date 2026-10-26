<?php

namespace App\Domain\Referentials\ReferenceData\Datasets;

use App\Domain\Imports\Support\CellValue;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\ReferenceData\ParsedRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Communes (F-REF-02) : une par nom (casse et accents ignorés), rattachée ou non à une DR. Une commune
 * utilisée ne se désactive pas : absente d'un fichier de remplacement, elle est gardée.
 */
class CommuneDataset extends ReferentialDataset
{
    /**
     * @var array<string, string>|null
     */
    private ?array $directorates = null;

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Nom *', 'help' => __('reference_data.help.name'), 'aliases' => ['Nom', 'Commune']],
            'regional_directorate' => ['label' => 'Code de la DR', 'help' => __('reference_data.help.directorate_code'), 'aliases' => ['DR', 'Direction régionale', 'Code DR']],
        ];
    }

    public function requiredColumns(): array
    {
        return ['name'];
    }

    public function labelColumn(): string
    {
        return 'name';
    }

    public function fileName(): string
    {
        return 'communes';
    }

    /**
     * @return Builder<Commune>
     */
    public function query(): Builder
    {
        return Commune::query();
    }

    public function parse(int $number, array $values): ParsedRow
    {
        $name = CellValue::text($values['name'] ?? null);
        $directorate = CellValue::text($values['regional_directorate'] ?? null);
        $directorateId = $directorate === null ? null : $this->directorateId($directorate);

        $errors = Fields::errors(['Nom' => [$name, 120, true]]);
        $warnings = $directorate !== null && $directorateId === null ? [__('reference_data.unknown_directorate_kept', ['value' => $directorate])] : [];

        return new ParsedRow($number, $name === null ? null : ImportWorkbook::normalize($name), $name, Fields::provided([
            'name' => $name,
            'regional_directorate_id' => $directorateId,
        ]), $errors, $warnings);
    }

    public function labelOf(Model $model): string
    {
        return (string) $model->getAttribute('name');
    }

    protected function keyOf(Model $model): string
    {
        return ImportWorkbook::normalize((string) $model->getAttribute('name'));
    }

    protected function deactivatable(): bool
    {
        return false;
    }

    protected function newModel(): Model
    {
        return new Commune;
    }

    private function directorateId(string $code): ?string
    {
        $this->directorates ??= RegionalDirectorate::query()->get(['id', 'code'])
            ->mapWithKeys(static fn (RegionalDirectorate $dr): array => [Str::upper($dr->code) => $dr->id])
            ->all();

        return $this->directorates[Str::upper($code)] ?? null;
    }
}
