<?php

namespace App\Domain\Exports\Support;

use App\Domain\Exports\Data\ExportSheet;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Classeur Excel d'un export (F-EXP-01), écrit en flux par OpenSpout (02-TRD §4.2) : une feuille par liste, en-têtes
 * en gras, puis la feuille « Informations » qui horodate l'export (RG-SEC-06 : date et heure, auteur, espace,
 * période, filtres, nombre de lignes). Le fichier de travail est effacé après lecture.
 */
final class ExportWorkbook
{
    private const HEADER_FILL = 'D9E2F3';

    private const COLUMN_WIDTH = 22;

    /**
     * @param  iterable<ExportSheet>  $sheets
     * @param  callable(int): array<string, string>  $information  lignes de la feuille « Informations », selon le nombre de lignes
     * @return array{contents: string, rows: int}
     */
    public function write(iterable $sheets, callable $information): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cga-export-');
        $writer = new Writer;
        $rows = 0;

        try {
            $writer->openToFile($path);
            $first = true;
            foreach ($sheets as $sheet) {
                $current = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $first = false;
                $current->setName(mb_substr($sheet->name, 0, 31));
                $current->setColumnWidthForRange(self::COLUMN_WIDTH, 1, max(1, count($sheet->headers)));
                $writer->addRow(Row::fromValuesWithStyle($sheet->headers, new Style(fontBold: true, backgroundColor: self::HEADER_FILL)));
                foreach ($sheet->rows as $row) {
                    $writer->addRow(Row::fromValues($row));
                    $rows++;
                }
            }

            $info = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $info->setName((string) __('exports.sheets.information'));
            $info->setColumnWidth(self::COLUMN_WIDTH, 1);
            $info->setColumnWidth(self::COLUMN_WIDTH * 3, 2);
            foreach ($information($rows) as $label => $value) {
                $writer->addRow(Row::fromValues([$label, $value]));
            }
            $writer->close();

            return ['contents' => (string) file_get_contents($path), 'rows' => $rows];
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
