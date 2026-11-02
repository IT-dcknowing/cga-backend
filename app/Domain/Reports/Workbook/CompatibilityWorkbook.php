<?php

namespace App\Domain\Reports\Workbook;

use App\Domain\Documents\Support\DocumentFormat;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Classeur officiel de compatibilité (RG-RPT-10, 02-TRD §8.3) rempli depuis l'objet de données du rapport
 * (QuarterlyReportCalculator) : page de garde et fiche technique (valeurs, dirigeants, salariés,
 * occasionnels, moyens) ; chaque feuille d'état reçoit un bloc par CDI, reproduit depuis le premier bloc
 * du modèle (titre, en-têtes, lignes, ligne TOTAL recalculée) ; la feuille RESUME reçoit une ligne par
 * CDI dans chacun de ses tableaux. Disposition : config reports.workbook. Aucune variable ${…} ne reste.
 */
final class CompatibilityWorkbook
{
    private const MARKER = '/\$\{([a-z0-9_]+)\}/';

    /**
     * @param  array<string, mixed>  $data  objet de données du rapport (instantané d'une version)
     */
    public function render(string $templatePath, string $outputPath, array $data): void
    {
        $book = IOFactory::createReader('Xlsx')->load($templatePath);
        $layout = (array) config('reports.workbook');

        $this->people($book->getSheetByNameOrThrow((string) $layout['profile_sheet']), $data);
        foreach ((array) $layout['blocks'] as $sheetKey => $block) {
            $this->blocks($book->getSheetByNameOrThrow((string) $block['sheet']), $block, $data['states']['sheets'][$sheetKey] ?? []);
        }
        $this->summary($book->getSheetByNameOrThrow((string) $layout['summary_sheet']), $data['states']['sheets']['summary'], (array) $layout['summary_tables']);
        $this->values($book, $this->globalValues($data));

        IOFactory::createWriter($book, 'Xlsx')->save($outputPath);
    }

    /**
     * Valeurs simples du classeur (page de garde, fiche technique, titres).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|int|float>
     */
    private function globalValues(array $data): array
    {
        $cga = $data['cga'];
        $report = $data['report'];
        $resources = $data['resources'];
        $content = $data['content'];
        $resource = static fn (string $category): int|string => isset($resources[$category]) ? (int) $resources[$category]['quantity'] : '';

        return [
            'report_quarter_label' => mb_strtoupper(__('report.quarters.'.$report['quarter']).' '.$report['year']),
            'report_date' => self::date($report['report_date']),
            'period_start' => self::date($report['period_start']),
            'period_end' => self::date($report['period_end']),
            'cga_name' => (string) $cga['name'],
            'cga_ncc' => (string) ($cga['ncc'] ?? ''),
            'cga_seat' => (string) ($cga['address'] ?? ''),
            'cga_location' => (string) ($cga['city'] ?? ''),
            'cga_contacts' => implode(' · ', array_filter([...$cga['phones'], $cga['email']])),
            'cga_agreement' => trim(($cga['agreement_number'] ?? '').' '.self::date($cga['agreement_date'])),
            'regional_directorate' => (string) ($cga['regional_directorate'] ?? ''),
            'cga_tax_service' => (string) ($cga['said'] ?? ''),
            'cga_legal_form_capital' => trim(($cga['legal_form'] ?? '').' '.($cga['share_capital'] === null ? '' : DocumentFormat::amount($cga['share_capital']))),
            'cga_history' => (string) ($cga['history'] ?? ''),
            'cga_previous_year_revenue' => $cga['previous_year_revenue'] ?? '',
            'res_rooms' => $resource('premises'),
            'res_training_rooms' => $resource('training_rooms'),
            'res_furniture' => $resource('furniture'),
            'res_computers' => $resource('computers'),
            'res_software' => $resource('software'),
            'res_projectors' => $resource('projectors'),
            'res_vehicles' => $resource('vehicles'),
            'res_other' => $resource('other'),
            'trainings' => (string) ($content['trainings'] ?? ''),
            'training_beneficiaries' => (string) ($content['assistance_beneficiaries'] ?? ''),
        ];
    }

    /**
     * Dirigeants, salariés permanents et occasionnels de la fiche technique, à partir de leur ligne
     * balisée ; des lignes sont insérées quand le modèle n'en réserve pas assez.
     *
     * @param  array<string, mixed>  $data
     */
    private function people(Worksheet $sheet, array $data): void
    {
        $lists = [
            'director_name' => array_map(static fn (array $p): array => [
                'director_name' => $p['full_name'], 'director_nationality' => $p['nationality'] ?? '', 'director_profession' => $p['profession'] ?? '',
                'director_diplomas' => $p['diplomas'] ?? '', 'director_function' => $p['function'] ?? '', 'director_start_date' => self::date($p['start_date']),
                'director_practice_structure' => $p['practice_structure'] ?? '', 'director_practice_function' => $p['practice_function'] ?? '',
                'director_practice_years' => $p['practice_years'] ?? '',
            ], $data['people']['directors']),
            'employee_name' => array_map(static fn (array $p): array => [
                'employee_name' => $p['full_name'], 'employee_nationality' => $p['nationality'] ?? '', 'employee_profession' => $p['profession'] ?? '',
                'employee_diplomas' => $p['diplomas'] ?? '', 'employee_function' => $p['function'] ?? '', 'employee_start_date' => self::date($p['start_date']),
                'employee_cnps' => (string) __('report.cnps.'.($p['cnps_declared'] === true ? 'declared' : 'not_declared')), 'employee_its' => '', 'employee_igr' => '',
            ], $data['people']['employees']),
            'occasional_name' => array_map(static fn (array $p): array => [
                'occasional_name' => $p['full_name'], 'occasional_nationality' => $p['nationality'] ?? '', 'occasional_profession' => $p['profession'] ?? '',
                'occasional_diplomas' => $p['diplomas'] ?? '', 'occasional_function' => $p['function'] ?? '', 'occasional_start_date' => self::date($p['start_date']),
                'occasional_duration' => '',
            ], $data['people']['occasionals']),
        ];

        foreach ($lists as $marker => $lines) {
            $row = $this->findRow($sheet, '${'.$marker.'}');
            if ($row === null) {
                continue;
            }
            $columns = $this->markedColumns($sheet, $row);
            $reserved = $this->reservedRows($sheet, $row);
            if (count($lines) - 1 > $reserved) {
                $this->insertRows($sheet, $row + 1 + $reserved, count($lines) - 1 - $reserved, $row);
            }
            foreach ($lines === [] ? [[]] : $lines as $offset => $line) {
                foreach ($columns as $column => $variable) {
                    $sheet->setCellValue($column.($row + $offset), $line[$variable] ?? '');
                }
            }
        }
    }

    /**
     * Blocs par CDI d'une feuille d'état : le premier bloc du modèle (de son titre « CDI … » à sa ligne
     * TOTAL) est relevé, les blocs du modèle sont retirés, puis un bloc est écrit par CDI.
     *
     * @param  array{sheet: string, first_data_offset: int, columns: array<string, string>, totals: list<string>}  $layout
     * @param  list<array{tax_office: array{name: string}|null, rows: list<array<string, mixed>>}>  $groups
     */
    private function blocks(Worksheet $sheet, array $layout, array $groups): void
    {
        $title = $this->findRow($sheet, '${tax_office_name}');
        if ($title === null) {
            return;
        }
        $firstData = $title + $layout['first_data_offset'];
        $total = $this->totalRow($sheet, $firstData);
        $lastTotal = $total;
        while (($next = $this->totalRow($sheet, $lastTotal + 1)) !== null) {
            $lastTotal = $next;
        }
        $model = $this->capture($sheet, $title, (int) $total);
        foreach ($sheet->getMergeCells() as $range) {
            [[, $r1], [, $r2]] = Coordinate::rangeBoundaries($range);
            if ($r2 >= $title && $r1 <= $lastTotal) {
                $sheet->unmergeCells($range);
            }
        }
        $sheet->removeRow($title, (int) $lastTotal - $title + 1);

        // Place des nouveaux blocs, pour ne rien écraser de ce qui suit les blocs du modèle.
        $groups = $groups === [] ? [['tax_office' => null, 'rows' => []]] : $groups;
        $needed = array_sum(array_map(static fn (array $group): int => $firstData - $title + max(1, count($group['rows'])) + 3, $groups));
        $sheet->insertNewRowBefore($title, $needed);

        $cursor = $title;
        foreach ($groups as $group) {
            $cursor = $this->writeBlock($sheet, $model, $layout, $group, $cursor, $firstData - $title, (int) $total - $title) + 2;
        }
    }

    /**
     * @param  array{cells: array<int, array<string, array{value: mixed, xf: int}>>, heights: array<int, float>, merges: list<array{0: int, 1: int, 2: int, 3: int}>}  $model
     * @param  array{columns: array<string, string>, totals: list<string>}  $layout
     * @param  array{tax_office: array{name: string}|null, rows: list<array<string, mixed>>}  $group
     * @return int dernière ligne écrite (TOTAL)
     */
    private function writeBlock(Worksheet $sheet, array $model, array $layout, array $group, int $start, int $dataOffset, int $totalOffset): int
    {
        $rows = $group['rows'];
        $count = max(1, count($rows));
        $name = $group['tax_office']['name'] ?? (string) __('report.no_tax_office');
        $map = static fn (int $offset): int => match (true) {
            $offset < $dataOffset => $start + $offset,
            $offset >= $totalOffset => $start + $dataOffset + $count + ($offset - $totalOffset),
            default => -1,
        };

        // Titre et en-têtes, puis la ligne TOTAL, à l'identique (styles, hauteurs, fusions).
        foreach ($model['cells'] as $offset => $cells) {
            $row = $map($offset);
            if ($row < 0) {
                continue;
            }
            foreach ($cells as $column => $cell) {
                $text = self::text($cell['value']);
                $value = $text !== null && str_contains($text, '${tax_office_name}') ? str_replace('${tax_office_name}', $name, $text) : $cell['value'];
                $sheet->getCell($column.$row)->setValue(is_string($value) && str_starts_with($value, '=') ? null : $value)->setXfIndex($cell['xf']);
            }
            if (isset($model['heights'][$offset])) {
                $sheet->getRowDimension($row)->setRowHeight($model['heights'][$offset]);
            }
        }
        foreach ($model['merges'] as [$c1, $r1, $c2, $r2]) {
            $from = $map($r1);
            $to = $map($r2);
            if ($from >= 0 && $to >= 0) {
                $sheet->mergeCells([$c1, $from, $c2, $to]);
            }
        }

        // Lignes de données : style de la première ligne du bloc type, valeurs de la ligne.
        $styles = $model['cells'][$dataOffset] ?? [];
        $first = $start + $dataOffset;
        for ($i = 0; $i < $count; $i++) {
            $row = $first + $i;
            foreach ($styles as $column => $cell) {
                $sheet->getCell($column.$row)->setValue(null)->setXfIndex($cell['xf']);
            }
            $sheet->setCellValue('A'.$row, $i + 1);
            foreach ($layout['columns'] as $column => $field) {
                $sheet->setCellValue($column.$row, self::cell($rows[$i] ?? [], $field));
            }
        }

        $totalRow = $first + $count;
        $sheet->setCellValue('A'.$totalRow, 'TOTAL');
        foreach ($layout['totals'] as $column) {
            $sheet->setCellValue($column.$totalRow, '=SUM('.$column.$first.':'.$column.($totalRow - 1).')');
        }

        return $totalRow;
    }

    /**
     * Tableaux de la feuille RESUME : une ligne par CDI (nom, valeur TEE ; nom, valeur RME), ligne TOTAL.
     *
     * @param  array{rows: list<array<string, mixed>>, totals: array<string, int>}  $summary
     * @param  list<array{first_row: int, tee: array{0: string, 1: string, 2: string}, rme: array{0: string, 1: string, 2: string}}>  $tables
     */
    private function summary(Worksheet $sheet, array $summary, array $tables): void
    {
        $rows = $summary['rows'] === [] ? [['tax_office' => null]] : $summary['rows'];
        $shift = 0;
        foreach ($tables as $table) {
            $first = $table['first_row'] + $shift;
            $total = $this->totalRow($sheet, $first) ?? $first + 1;
            $slots = $total - $first;
            if (count($rows) > $slots) {
                $this->insertRows($sheet, $total, count($rows) - $slots, $first);
            } elseif (count($rows) < $slots) {
                $sheet->removeRow($first + count($rows), $slots - count($rows));
            }
            $shift += count($rows) - $slots;
            foreach ($rows as $i => $row) {
                $name = $row['tax_office']['name'] ?? (string) __('report.no_tax_office');
                foreach ([$table['tee'], $table['rme']] as [$nameColumn, $valueColumn, $field]) {
                    $sheet->setCellValue($nameColumn.($first + $i), $name);
                    $sheet->setCellValue($valueColumn.($first + $i), (int) ($row[$field] ?? 0));
                }
            }
            $totalRow = $first + count($rows);
            foreach ([$table['tee'][1], $table['rme'][1]] as $column) {
                $sheet->setCellValue($column.$totalRow, '=SUM('.$column.$first.':'.$column.($totalRow - 1).')');
            }
        }
    }

    /**
     * Remplace les variables restantes de tout le classeur ; une variable sans valeur est effacée.
     *
     * @param  array<string, string|int|float>  $values
     */
    private function values(Spreadsheet $book, array $values): void
    {
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $text = self::text($sheet->getCell($coordinate)->getValue());
                if ($text === null || preg_match(self::MARKER, $text) !== 1) {
                    continue;
                }
                if (preg_match('/^\$\{([a-z0-9_]+)\}$/', $text, $match) === 1) {
                    $sheet->getCell($coordinate)->setValue($values[$match[1]] ?? '');

                    continue;
                }
                $sheet->getCell($coordinate)->setValue((string) preg_replace_callback(self::MARKER, static fn (array $m): string => (string) ($values[$m[1]] ?? ''), $text));
            }
        }
    }

    /**
     * Valeur d'une cellule de données : identité composée, dates au format français, taux en fraction.
     *
     * @param  array<string, mixed>  $row
     */
    private static function cell(array $row, string $field): string|int|float
    {
        $value = match ($field) {
            'number_and_date' => trim(($row['membership_number'] ?? '').' '.self::date($row['adhesion_date'] ?? null)),
            'date_and_reason' => trim(self::date($row['effective_date'] ?? null).' '.($row['reason'] ?? '').(empty($row['reason_other']) ? '' : ' ('.$row['reason_other'].')')),
            'registration_date' => self::date($row['registration_date'] ?? null),
            'annual_rate', 'quarter_rate' => isset($row[$field]) ? round((float) $row[$field] / 100, 4) : '',
            default => $row[$field] ?? '',
        };

        return is_string($value) || is_int($value) || is_float($value) ? $value : '';
    }

    private static function date(?string $date): string
    {
        return $date === null ? '' : CarbonImmutable::parse($date)->format('d/m/Y');
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value instanceof RichText => $value->getPlainText(),
            is_string($value) => $value,
            default => null,
        };
    }

    private function findRow(Worksheet $sheet, string $needle): ?int
    {
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $text = self::text($sheet->getCell($coordinate)->getValue());
            if ($text !== null && str_contains($text, $needle)) {
                return (int) Coordinate::coordinateFromString($coordinate)[1];
            }
        }

        return null;
    }

    /** Première ligne « TOTAL » (colonne A) à partir d'une ligne. */
    private function totalRow(Worksheet $sheet, int $from): ?int
    {
        for ($row = $from; $row <= $sheet->getHighestRow(); $row++) {
            if (mb_strtoupper(trim((string) self::text($sheet->getCell('A'.$row)->getValue()))) === 'TOTAL') {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> colonne => variable de la ligne balisée
     */
    private function markedColumns(Worksheet $sheet, int $row): array
    {
        $columns = [];
        foreach ($sheet->getRowIterator($row, $row)->current()->getCellIterator() as $cell) {
            $text = self::text($cell->getValue());
            if ($text !== null && preg_match('/^\$\{([a-z0-9_]+)\}$/', $text, $match) === 1) {
                $columns[$cell->getColumn()] = $match[1];
            }
        }

        return $columns;
    }

    /** Lignes vides réservées sous une ligne balisée (seul le numéro d'ordre y figure). */
    private function reservedRows(Worksheet $sheet, int $row): int
    {
        $reserved = 0;
        for ($next = $row + 1; $next <= $sheet->getHighestRow(); $next++) {
            foreach ($sheet->getRowIterator($next, $next)->current()->getCellIterator() as $cell) {
                if (! in_array($cell->getColumn(), ['A', 'B'], true) && self::text($cell->getValue()) !== null && trim((string) self::text($cell->getValue())) !== '') {
                    return $reserved;
                }
            }
            $reserved++;
        }

        return $reserved;
    }

    /** Insère des lignes avant une ligne, au style d'une ligne type. */
    private function insertRows(Worksheet $sheet, int $before, int $count, int $styleRow): void
    {
        $sheet->insertNewRowBefore($before, $count);
        $styleRow = $styleRow >= $before ? $styleRow + $count : $styleRow;
        foreach ($sheet->getRowIterator($styleRow, $styleRow)->current()->getCellIterator() as $cell) {
            for ($row = $before; $row < $before + $count; $row++) {
                $sheet->getCell($cell->getColumn().$row)->setXfIndex($cell->getXfIndex());
            }
        }
    }

    /**
     * Relève un bloc type : valeurs et styles par ligne (décalage depuis le titre), hauteurs, fusions.
     *
     * @return array{cells: array<int, array<string, array{value: mixed, xf: int}>>, heights: array<int, float>, merges: list<array{0: int, 1: int, 2: int, 3: int}>}
     */
    private function capture(Worksheet $sheet, int $from, int $to): array
    {
        $cells = $heights = [];
        for ($row = $from; $row <= $to; $row++) {
            foreach ($sheet->getRowIterator($row, $row)->current()->getCellIterator() as $cell) {
                $cells[$row - $from][$cell->getColumn()] = ['value' => $cell->getValue(), 'xf' => $cell->getXfIndex()];
            }
            $height = $sheet->getRowDimension($row)->getRowHeight();
            if ($height > 0) {
                $heights[$row - $from] = $height;
            }
        }
        $merges = [];
        foreach ($sheet->getMergeCells() as $range) {
            [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($range);
            if ($r1 >= $from && $r2 <= $to) {
                $merges[] = [$c1, $r1 - $from, $c2, $r2 - $from];
                $sheet->unmergeCells($range);
            }
        }

        return ['cells' => $cells, 'heights' => $heights, 'merges' => $merges];
    }
}
