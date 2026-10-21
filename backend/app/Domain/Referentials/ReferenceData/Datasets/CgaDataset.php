<?php

namespace App\Domain\Referentials\ReferenceData\Datasets;

use App\Domain\Documents\Models\File;
use App\Domain\Imports\Importers\CgaRowImporter;
use App\Domain\Imports\Support\CellValue;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Domain\Organizations\Actions\CreateCga;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\ReferenceData\DatasetDefinition;
use App\Domain\Referentials\ReferenceData\ParsedRow;
use App\Domain\Referentials\ReferenceData\Removal;
use App\Domain\Users\Models\Membership;
use App\Support\Phones\PhoneNormalizer;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Liste des CGA du registre (RG-ORG-01, RG-DATA-01), modèle de l'import F-IMP-01. Une ligne désigne le
 * CGA de même numéro d'agrément, sinon de même dénomination (casse et accents ignorés) : remplacer la
 * liste provisoire par le fichier réel met à jour ces CGA sans toucher à leurs comptes ni à leurs
 * données. Un CGA absent d'un fichier de remplacement est supprimé s'il n'a ni compte ni fichier,
 * gardé sinon. Une cellule vide ne change rien ; l'agrément passe « agréé » quand son numéro et sa
 * date sont connus, jamais l'inverse.
 */
class CgaDataset implements DatasetDefinition
{
    /**
     * @var array{agreements: array<string, Organization>, names: array<string, Organization>}|null
     */
    private ?array $index = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $directorates = null;

    public function __construct(
        private readonly CgaRowImporter $layout,
        private readonly CreateCga $createCga,
        private readonly PhoneNormalizer $phones,
    ) {}

    public function columns(): array
    {
        return $this->layout->columns();
    }

    public function requiredColumns(): array
    {
        return ['name', 'city'];
    }

    public function labelColumn(): string
    {
        return 'name';
    }

    public function fileName(): string
    {
        return 'registre-cga';
    }

    /**
     * @return Builder<Organization>
     */
    public function query(): Builder
    {
        return Organization::query()->where('type', OrganizationType::Cga);
    }

    public function parse(int $number, array $values): ParsedRow
    {
        $name = CellValue::text($values['name'] ?? null);
        $sigle = CellValue::text($values['sigle'] ?? null);
        $agreementNumber = CellValue::text($values['agreement_number'] ?? null);
        $date = CellValue::date($values['agreement_date'] ?? null);
        $city = CellValue::text($values['city'] ?? null);
        $directorate = CellValue::text($values['regional_directorate'] ?? null);
        $phone = CellValue::text($values['phone_1'] ?? null);
        $email = CellValue::text($values['email'] ?? null);
        $phoneE164 = $phone === null ? null : $this->phones->e164($phone);
        $directorateId = $directorate === null ? null : $this->directorateId($directorate);

        $errors = [
            ...Fields::errors(['Dénomination' => [$name, 200, true], 'Sigle' => [$sigle, 50, false], 'N° d\'agrément' => [$agreementNumber, 60, false], 'Ville' => [$city, 100, true], 'E-mail' => [$email, 255, false]]),
            ...array_values(array_filter([
                $phone !== null && $phoneE164 === null ? __('imports.cgas.invalid_phone', ['value' => $phone]) : null,
                $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false ? __('imports.cgas.invalid_email', ['value' => $email]) : null,
                $date === false ? __('imports.cgas.invalid_date') : null,
                $date instanceof DateTimeInterface && $date > now() ? __('imports.cgas.future_date') : null,
            ])),
        ];
        $warnings = $directorate !== null && $directorateId === null ? [__('reference_data.unknown_directorate_kept', ['value' => $directorate])] : [];

        $key = $agreementNumber !== null ? 'agrement:'.Str::upper($agreementNumber) : ($name === null ? null : 'nom:'.ImportWorkbook::normalize($name));

        return new ParsedRow($number, $key, $name, Fields::provided([
            'name' => $name,
            'sigle' => $sigle,
            'agreement_number' => $agreementNumber,
            'agreement_date' => $date instanceof DateTimeInterface ? $date->format('Y-m-d') : null,
            'city' => $city,
            'regional_directorate_id' => $directorateId,
            'phone_1' => $phoneE164,
            'email' => $email,
        ]), $errors, $warnings);
    }

    public function find(ParsedRow $row): ?Model
    {
        if ($this->index === null) {
            $this->index = ['agreements' => [], 'names' => []];
            foreach (Organization::query()->where('type', OrganizationType::Cga)->get() as $organization) {
                if ($organization->agreement_number !== null) {
                    $this->index['agreements'][Str::upper($organization->agreement_number)] = $organization;
                }
                $this->index['names'][ImportWorkbook::normalize($organization->name)] = $organization;
            }
        }

        $number = $row->attributes['agreement_number'] ?? null;
        $name = $row->attributes['name'] ?? null;

        return (is_string($number) ? ($this->index['agreements'][Str::upper($number)] ?? null) : null)
            ?? (is_string($name) ? ($this->index['names'][ImportWorkbook::normalize($name)] ?? null) : null);
    }

    public function conflicts(ParsedRow $row, ?Model $match): array
    {
        $number = $row->attributes['agreement_number'] ?? null;
        $name = $row->attributes['name'] ?? null;
        $holder = is_string($number) ? Organization::query()->where('agreement_number', $number)->first() : null;
        $namesake = is_string($name) ? ($this->index['names'][ImportWorkbook::normalize($name)] ?? null) : null;

        return array_values(array_filter([
            $match === null && ! isset($row->attributes['phone_1']) ? __('reference_data.cga_phone_required') : null,
            $holder !== null && ! $holder->is($match) ? __('imports.cgas.duplicate_agreement', ['number' => $number]) : null,
            $namesake !== null && ! $namesake->is($match) ? __('imports.cgas.duplicate_name') : null,
        ]));
    }

    public function changes(Model $model, ParsedRow $row): array
    {
        $changes = [];
        foreach ($row->attributes as $field => $value) {
            $current = $model->getAttribute($field);
            $current = $current instanceof DateTimeInterface ? $current->format('Y-m-d') : $current;
            if ($current !== $value) {
                $changes[$field] = [$current, $value];
            }
        }

        return $changes;
    }

    public function create(ParsedRow $row): Model
    {
        $granted = isset($row->attributes['agreement_number'], $row->attributes['agreement_date']);

        return $this->createCga->execute([
            ...$row->attributes,
            'agreement_status' => ($granted ? AgreementStatus::Granted : AgreementStatus::Pending)->value,
        ]);
    }

    public function update(Model $model, ParsedRow $row): Model
    {
        $model->forceFill($row->attributes);
        if ($model->getAttribute('agreement_status') === AgreementStatus::Pending && $model->getAttribute('agreement_number') !== null && $model->getAttribute('agreement_date') !== null) {
            $model->setAttribute('agreement_status', AgreementStatus::Granted);
        }
        $model->save();

        return $model;
    }

    public function removal(Model $model): Removal
    {
        $used = Membership::query()->where('organization_id', $model->getKey())->exists()
            || File::query()->where('organization_id', $model->getKey())->exists();

        return $used ? Removal::Keep : Removal::Delete;
    }

    public function remove(Model $model, Removal $removal): void
    {
        if ($removal === Removal::Delete) {
            $model->delete();
        }
    }

    public function labelOf(Model $model): string
    {
        return (string) $model->getAttribute('name');
    }

    private function directorateId(string $designation): ?string
    {
        if ($this->directorates === null) {
            $this->directorates = [];
            foreach (RegionalDirectorate::query()->get(['id', 'code', 'name']) as $directorate) {
                $this->directorates[ImportWorkbook::normalize($directorate->code)] = $directorate->id;
                $this->directorates[ImportWorkbook::normalize($directorate->name)] ??= $directorate->id;
            }
        }

        return $this->directorates[ImportWorkbook::normalize($designation)] ?? null;
    }
}
