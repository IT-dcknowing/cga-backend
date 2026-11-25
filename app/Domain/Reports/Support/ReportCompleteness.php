<?php

namespace App\Domain\Reports\Support;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Enums\CgaPersonKind;
use App\Domain\Reports\Enums\CgaResourceCategory;
use App\Domain\Reports\Models\CgaPerson;
use App\Domain\Reports\Models\CgaResource;
use App\Domain\Reports\Models\QuarterlyReport;

/**
 * Complétude par section du rapport trimestriel (RG-RPT-02) : champs requis renseignés / champs
 * requis, en pourcentage entier. Section 1 : fiche technique ; 2 : au moins un dirigeant actif et ses
 * impôts du trimestre ; 3 : chaque catégorie de moyens matériels (hors « autres ») ; 4 et 6 : champs
 * saisis requis (config reports.content, analyse qualitative en section 6) ; 5 : états calculés,
 * toujours complète. Un texte peut valoir « Néant » ; un nombre peut valoir 0. Le rapport est générable
 * quand toutes les sections sont à 100 %.
 */
final class ReportCompleteness
{
    /**
     * @return array<string, int> section_1 … section_6
     */
    public function compute(QuarterlyReport $report, Organization $cga): array
    {
        return [
            'section_1' => self::percent($this->section1($report, $cga)),
            'section_2' => self::percent($this->section2($report)),
            'section_3' => self::percent($this->section3($report)),
            'section_4' => self::percent(self::fields((array) config('reports.content.section_4'), $report->content)),
            'section_5' => 100,
            'section_6' => self::percent(self::add(
                self::fields((array) config('reports.content.section_6'), $report->content),
                self::fields((array) config('reports.qualitative_analysis'), $report->qualitative_analysis, 'text'),
            )),
        ];
    }

    /**
     * @param  array<string, int>  $completeness
     */
    public static function isComplete(array $completeness): bool
    {
        return $completeness !== [] && min($completeness) === 100;
    }

    /**
     * @param  array<string, int>  $completeness
     */
    public static function overall(array $completeness): int
    {
        return $completeness === [] ? 0 : intdiv(array_sum($completeness), count($completeness));
    }

    /**
     * Valeur saisie renseignée : texte non vide, nombre entier.
     */
    public static function filled(mixed $value, string $type): bool
    {
        return $type === 'integer' ? is_int($value) : is_string($value) && trim($value) !== '';
    }

    /**
     * @return array{0: int, 1: int} renseignés, requis
     */
    private function section1(QuarterlyReport $report, Organization $cga): array
    {
        $fields = (array) config('reports.section_1_fields');
        $filled = 0;
        foreach ($fields as $field) {
            [$source, $column] = explode('.', (string) $field, 2);
            $value = $source === 'cga' ? $cga->getAttribute($column) : $report->getAttribute($column);
            $filled += $value === null || (is_string($value) && trim($value) === '') ? 0 : 1;
        }

        return [$filled, count($fields)];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function section2(QuarterlyReport $report): array
    {
        $directors = CgaPerson::query()
            ->where('organization_id', $report->organization_id)
            ->where('kind', CgaPersonKind::Director)
            ->where('active', true)
            ->pluck('id')
            ->all();
        $taxes = collect($report->people_taxes)->keyBy('person_id');
        $columns = (array) config('reports.people_taxes');
        $filled = $directors === [] ? 0 : 1;
        foreach ($directors as $id) {
            foreach ($columns as $column) {
                $filled += is_int($taxes->get($id)[$column] ?? null) ? 1 : 0;
            }
        }

        return [$filled, 1 + count($directors) * count($columns)];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function section3(QuarterlyReport $report): array
    {
        $required = array_filter(CgaResourceCategory::cases(), static fn (CgaResourceCategory $category): bool => $category !== CgaResourceCategory::Other);
        $entered = CgaResource::query()
            ->where('organization_id', $report->organization_id)
            ->whereIn('category', $required)
            ->count();

        return [$entered, count($required)];
    }

    /**
     * @param  array<string, array{type?: string, required: bool}>  $definitions
     * @param  array<string, mixed>  $values
     * @return array{0: int, 1: int}
     */
    private static function fields(array $definitions, array $values, string $defaultType = 'text'): array
    {
        $filled = $required = 0;
        foreach ($definitions as $key => $definition) {
            if (! $definition['required']) {
                continue;
            }
            $required++;
            $filled += self::filled($values[$key] ?? null, $definition['type'] ?? $defaultType) ? 1 : 0;
        }

        return [$filled, $required];
    }

    /**
     * @param  array{0: int, 1: int}  $a
     * @param  array{0: int, 1: int}  $b
     * @return array{0: int, 1: int}
     */
    private static function add(array $a, array $b): array
    {
        return [$a[0] + $b[0], $a[1] + $b[1]];
    }

    /**
     * @param  array{0: int, 1: int}  $counts
     */
    private static function percent(array $counts): int
    {
        [$filled, $required] = $counts;

        return $required === 0 ? 100 : intdiv(100 * $filled, $required);
    }
}
