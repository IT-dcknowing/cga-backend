<?php

namespace App\Domain\Reports\Workbook;

use App\Domain\Documents\Contracts\ChecksTemplateStructure;
use Illuminate\Contracts\Config\Repository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Contrôle de structure du classeur officiel du SP-CGA (06-IMPLEMENTATION-PLAN étape 5.10, 02-TRD §8.3) :
 * une nouvelle version déposée par l'écran des modèles (3.12) n'est acceptée que si le remplissage
 * (CompatibilityWorkbook) y retrouve ce qu'il attend — nombre de feuilles, feuilles nommées de la
 * disposition config reports.workbook, dans chaque feuille d'état un bloc type « CDI » (titre
 * ${tax_office_name} puis ligne TOTAL en colonne A), dans la feuille RESUME une ligne TOTAL par tableau.
 * Ainsi la version officielle remplace la version provisoire sans développement.
 */
final class WorkbookStructure implements ChecksTemplateStructure
{
    private const TAX_OFFICE_TITLE = '${tax_office_name}';

    public function __construct(private readonly Repository $config) {}

    public function problems(string $path): array
    {
        /** @var array{sheet_count: int, cover_sheet: string, profile_sheet: string, summary_sheet: string, blocks: array<string, array{sheet: string, first_data_offset: int}>, summary_tables: list<array{first_row: int}>} $layout */
        $layout = (array) $this->config->get('reports.workbook');
        $book = IOFactory::load($path);
        $problems = [];

        if ($book->getSheetCount() !== $layout['sheet_count']) {
            $problems[] = (string) __('report.workbook.sheet_count', ['expected' => $layout['sheet_count'], 'found' => $book->getSheetCount()]);
        }
        $sheets = [$layout['cover_sheet'], $layout['profile_sheet'], $layout['summary_sheet'], ...array_column($layout['blocks'], 'sheet')];
        foreach ($sheets as $name) {
            if ($book->getSheetByName($name) === null) {
                $problems[] = (string) __('report.workbook.missing_sheet', ['sheet' => $name]);
            }
        }

        foreach ($layout['blocks'] as $block) {
            $sheet = $book->getSheetByName($block['sheet']);
            $title = $sheet === null ? null : $this->findRow($sheet, self::TAX_OFFICE_TITLE);
            if ($sheet !== null && ($title === null || $this->totalRow($sheet, $title + $block['first_data_offset']) === null)) {
                $problems[] = (string) __('report.workbook.missing_block', ['sheet' => $block['sheet']]);
            }
        }

        $summary = $book->getSheetByName($layout['summary_sheet']);
        if ($summary !== null) {
            foreach ($layout['summary_tables'] as $index => $table) {
                if ($this->totalRow($summary, $table['first_row']) === null) {
                    $problems[] = (string) __('report.workbook.missing_summary_total', ['sheet' => $layout['summary_sheet'], 'table' => $index + 1, 'row' => $table['first_row']]);
                }
            }
        }

        return $problems;
    }

    private function findRow(Worksheet $sheet, string $needle): ?int
    {
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            if (str_contains(self::text($sheet->getCell($coordinate)->getValue()), $needle)) {
                return $sheet->getCell($coordinate)->getRow();
            }
        }

        return null;
    }

    private function totalRow(Worksheet $sheet, int $from): ?int
    {
        for ($row = $from; $row <= $sheet->getHighestRow(); $row++) {
            if (mb_strtoupper(trim(self::text($sheet->getCell('A'.$row)->getValue()))) === 'TOTAL') {
                return $row;
            }
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        return $value instanceof RichText ? $value->getPlainText() : (is_scalar($value) ? (string) $value : '');
    }
}
