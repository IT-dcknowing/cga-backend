<?php

namespace App\Domain\Reports\Composers;

use App\Domain\Documents\Composers\SpreadsheetComposer;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Reports\Workbook\CompatibilityWorkbook;

/**
 * Classeur officiel de compatibilité d'une version de rapport (RG-RPT-10), rempli depuis son instantané.
 */
class QuarterlyReportWorkbookComposer implements SpreadsheetComposer
{
    public function __construct(private readonly CompatibilityWorkbook $workbook) {}

    public function render(GeneratedDocument $document, string $templatePath, string $outputPath): void
    {
        $this->workbook->render($templatePath, $outputPath, ReportSnapshot::of($document));
    }
}
