<?php

namespace App\Domain\Reports\Workbook;

use App\Domain\Documents\Templates\IncompleteTemplateDataException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Remplit le classeur officiel SP-CGA (02-TRD §8.3, décision de l'étape 0.11 : PhpSpreadsheet) : chaque
 * cellule balisée ${…} reçoit sa valeur — un nombre reste un nombre — et les lignes d'un bloc
 * (adhérents d'un CDI, personnel) s'écrivent à partir de la ligne balisée du bloc. Les formes dessinées,
 * mises en forme et formules du modèle sont conservées ; les formules sont recalculées à l'ouverture.
 */
class OfficialWorkbookFiller
{
    private const MARKER = '/\$\{([a-z0-9_]+)\}/';

    /**
     * @param  array<string, string|int|float>  $values  variable => valeur
     * @param  array<string, array<int, list<array<string, string|int|float>>>>  $rows  feuille => ligne balisée => lignes (variable => valeur)
     *
     * @throws IncompleteTemplateDataException
     */
    public function fill(string $templatePath, string $outputPath, array $values, array $rows = []): void
    {
        $spreadsheet = IOFactory::createReader('Xlsx')->load($templatePath);

        foreach ($rows as $sheetName => $blocks) {
            $sheet = $spreadsheet->getSheetByNameOrThrow($sheetName);
            foreach ($blocks as $firstRow => $lines) {
                $this->writeRows($sheet, $firstRow, $lines);
            }
        }

        $missing = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $text = $this->text($sheet->getCell($coordinate)->getValue());
                if ($text === null || preg_match(self::MARKER, $text) !== 1) {
                    continue;
                }
                $sheet->getCell($coordinate)->setValue($this->replace($text, $values, $missing));
            }
        }

        if ($missing !== []) {
            throw new IncompleteTemplateDataException(array_values(array_unique($missing)));
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);
    }

    /**
     * Écrit les lignes d'un bloc : la ligne balisée sert de modèle, les suivantes reçoivent la même
     * disposition (colonnes balisées) dans les lignes vides prévues sous elle.
     *
     * @param  list<array<string, string|int|float>>  $lines
     */
    private function writeRows(Worksheet $sheet, int $firstRow, array $lines): void
    {
        $columns = [];
        foreach ($sheet->getRowIterator($firstRow, $firstRow)->current()->getCellIterator() as $cell) {
            $text = $this->text($cell->getValue());
            if ($text !== null && preg_match('/^\$\{([a-z0-9_]+)\}$/', $text, $match) === 1) {
                $columns[$cell->getColumn()] = $match[1];
            }
        }

        foreach ($lines as $offset => $line) {
            foreach ($columns as $column => $variable) {
                $sheet->setCellValue($column.($firstRow + $offset), $line[$variable] ?? '');
            }
        }
    }

    /**
     * @param  array<string, string|int|float>  $values
     * @param  list<string>  $missing
     */
    private function replace(string $text, array $values, array &$missing): string|int|float
    {
        if (preg_match('/^\$\{([a-z0-9_]+)\}$/', $text, $match) === 1) {
            if (! array_key_exists($match[1], $values)) {
                $missing[] = $match[1];

                return '';
            }

            return $values[$match[1]];
        }

        return (string) preg_replace_callback(self::MARKER, static function (array $match) use ($values, &$missing): string {
            if (! array_key_exists($match[1], $values)) {
                $missing[] = $match[1];

                return '';
            }

            return (string) $values[$match[1]];
        }, $text);
    }

    private function text(mixed $value): ?string
    {
        return match (true) {
            $value instanceof RichText => $value->getPlainText(),
            is_string($value) => $value,
            default => null,
        };
    }
}
