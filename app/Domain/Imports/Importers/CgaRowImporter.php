<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Contracts\RowImporter;
use App\Domain\Imports\Data\RowOutcome;
use App\Domain\Imports\Support\CellValue;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Domain\Organizations\Actions\CreateCga;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Support\Phones\PhoneNormalizer;
use Carbon\CarbonImmutable;

/**
 * Import du registre des CGA (F-IMP-01, RG-ORG-01) : fiche minimale par ligne (dénomination, sigle,
 * agrément, date d'agrément, ville, DR, téléphone, e-mail), CGA créé non abonné, en données
 * provisoires (RG-DATA-02). Ligne complète : importée. Agrément incomplet (créé « en cours »,
 * RG-ORG-03), DR absente ou inconnue, e-mail absent : importée à compléter, manques listés. Champ
 * obligatoire absent, valeur invalide ou doublon (même dénomination, même n° d'agrément) : rejetée.
 */
class CgaRowImporter implements RowImporter
{
    /**
     * Directions régionales par code et par nom normalisés.
     *
     * @var array<string, string>|null
     */
    private ?array $directorates = null;

    public function __construct(
        private readonly CreateCga $create,
        private readonly PhoneNormalizer $phones,
    ) {}

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Dénomination *', 'help' => __('imports.cgas.help.name'), 'aliases' => ['Dénomination', 'Nom', 'Nom du CGA', 'Raison sociale']],
            'sigle' => ['label' => 'Sigle', 'help' => __('imports.cgas.help.sigle'), 'aliases' => []],
            'agreement_number' => ['label' => 'N° d\'agrément', 'help' => __('imports.cgas.help.agreement_number'), 'aliases' => ['Agrément', 'Numéro d\'agrément', 'N° agrément']],
            'agreement_date' => ['label' => 'Date d\'agrément', 'help' => __('imports.cgas.help.agreement_date'), 'aliases' => ['Date agrément']],
            'city' => ['label' => 'Ville *', 'help' => __('imports.cgas.help.city'), 'aliases' => ['Ville']],
            'regional_directorate' => ['label' => 'Direction régionale', 'help' => __('imports.cgas.help.regional_directorate'), 'aliases' => ['DR']],
            'phone_1' => ['label' => 'Téléphone *', 'help' => __('imports.cgas.help.phone_1'), 'aliases' => ['Téléphone', 'Tél', 'Contact']],
            'email' => ['label' => 'E-mail', 'help' => __('imports.cgas.help.email'), 'aliases' => ['Email', 'Courriel', 'Adresse e-mail']],
        ];
    }

    public function requiredColumns(): array
    {
        return ['name', 'city', 'phone_1'];
    }

    public function labelColumn(): string
    {
        return 'name';
    }

    public function fileName(): string
    {
        return 'import-registre-cga';
    }

    public function import(array $values): RowOutcome
    {
        $name = CellValue::text($values['name'] ?? null);
        $sigle = CellValue::text($values['sigle'] ?? null);
        $number = CellValue::text($values['agreement_number'] ?? null);
        $date = CellValue::date($values['agreement_date'] ?? null);
        $city = CellValue::text($values['city'] ?? null);
        $directorate = CellValue::text($values['regional_directorate'] ?? null);
        $phone = CellValue::text($values['phone_1'] ?? null);
        $email = CellValue::text($values['email'] ?? null);
        $phoneE164 = $phone === null ? null : $this->phones->e164($phone);

        $reasons = array_values(array_filter([
            $name === null ? __('imports.cgas.required', ['field' => 'Dénomination']) : null,
            $city === null ? __('imports.cgas.required', ['field' => 'Ville']) : null,
            $phone === null ? __('imports.cgas.required', ['field' => 'Téléphone']) : null,
            $phone !== null && $phoneE164 === null ? __('imports.cgas.invalid_phone', ['value' => $phone]) : null,
            $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false ? __('imports.cgas.invalid_email', ['value' => $email]) : null,
            $date === false ? __('imports.cgas.invalid_date') : null,
            $date instanceof CarbonImmutable && $date->isFuture() ? __('imports.cgas.future_date') : null,
            ...$this->tooLong(['Dénomination' => [$name, 200], 'Sigle' => [$sigle, 50], 'N° d\'agrément' => [$number, 60], 'Ville' => [$city, 100], 'E-mail' => [$email, 255]]),
            $name !== null && $this->nameTaken($name) ? __('imports.cgas.duplicate_name') : null,
            $number !== null && Organization::query()->where('agreement_number', $number)->exists() ? __('imports.cgas.duplicate_agreement', ['number' => $number]) : null,
        ]));
        if ($reasons !== [] || $name === null || $city === null || $phoneE164 === null) {
            return RowOutcome::rejected($name, $reasons === [] ? [__('imports.cgas.invalid_row')] : $reasons);
        }

        $agreementDate = $date instanceof CarbonImmutable ? $date : null;
        $directorateId = $directorate === null ? null : $this->directorateId($directorate);
        $gaps = array_values(array_filter([
            $number === null || $agreementDate === null ? __('imports.cgas.agreement_incomplete') : null,
            $directorate === null ? __('imports.cgas.missing', ['field' => 'Direction régionale']) : null,
            $directorate !== null && $directorateId === null ? __('imports.cgas.unknown_directorate', ['value' => $directorate]) : null,
            $email === null ? __('imports.cgas.missing', ['field' => 'E-mail']) : null,
        ]));

        $organization = $this->create->execute([
            'name' => $name,
            'sigle' => $sigle,
            'city' => $city,
            'agreement_status' => $number !== null && $agreementDate !== null ? AgreementStatus::Granted->value : AgreementStatus::Pending->value,
            'agreement_number' => $number,
            'agreement_date' => $agreementDate?->toDateString(),
            'regional_directorate_id' => $directorateId,
            'phone_1' => $phoneE164,
            'email' => $email,
        ]);

        return $gaps === [] ? RowOutcome::imported($organization->id, $name) : RowOutcome::draft($organization->id, $name, $gaps);
    }

    /**
     * @param  array<string, array{0: string|null, 1: int}>  $fields  libellé => [valeur, longueur maximale]
     * @return list<string>
     */
    private function tooLong(array $fields): array
    {
        $reasons = [];
        foreach ($fields as $label => [$value, $max]) {
            if ($value !== null && mb_strlen($value) > $max) {
                $reasons[] = __('imports.cgas.too_long', ['field' => $label, 'max' => $max]);
            }
        }

        return $reasons;
    }

    /**
     * Un CGA du registre porte déjà cette dénomination (casse et accents ignorés).
     */
    private function nameTaken(string $name): bool
    {
        return Organization::query()
            ->where('type', OrganizationType::Cga)
            ->whereRaw('lower(unaccent(name)) = lower(unaccent(?))', [$name])
            ->exists();
    }

    /**
     * Direction régionale désignée par son code ou par son nom.
     */
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
