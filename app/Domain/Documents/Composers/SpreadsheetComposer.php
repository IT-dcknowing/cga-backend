<?php

namespace App\Domain\Documents\Composers;

use App\Domain\Documents\Models\GeneratedDocument;

/**
 * Document produit sous forme de classeur (XLSX, sans PDF) : le composeur remplit lui-même le modèle
 * (02-TRD §8.3 : classeur officiel de compatibilité du rapport trimestriel).
 */
interface SpreadsheetComposer
{
    public function render(GeneratedDocument $document, string $templatePath, string $outputPath): void;
}
