<?php

use App\Domain\Documents\Samples\SampleDocumentData;
use App\Domain\Documents\Templates\IncompleteTemplateDataException;
use App\Domain\Reports\Workbook\OfficialWorkbookFiller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/*
 * Classeur officiel SP-CGA rempli avec PhpSpreadsheet (02-TRD §8.3, décision de l'étape 0.11) : les
 * 14 feuilles, les formes dessinées et les formules du modèle sont conservées.
 */

beforeEach(function () {
    $this->work = storage_path('framework/testing/xlsx-'.Str::random(8));
    File::ensureDirectoryExists($this->work);
    $this->output = $this->work.'/rapport.xlsx';
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

/**
 * @return array<string, string>
 */
function workbookDrawings(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $drawings = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_starts_with($name, 'xl/drawings/')) {
            // Seules la déclaration XML et ses fins de ligne peuvent changer à l'enregistrement.
            $drawings[$name] = trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', (string) $zip->getFromName($name)));
        }
    }
    $zip->close();
    ksort($drawings);

    return $drawings;
}

it('remplit le classeur en gardant ses 14 feuilles et ses formes dessinées à l\'identique', function () {
    $template = config('documents.templates.quarterly_report_xlsx.path');
    $sample = app(SampleDocumentData::class)->workbook();

    app(OfficialWorkbookFiller::class)->fill($template, $this->output, $sample['values'], $sample['rows']);

    $spreadsheet = IOFactory::load($this->output);
    $fiche = $spreadsheet->getSheetByNameOrThrow('Fiche tech CGA');
    $tee = $spreadsheet->getSheetByNameOrThrow('Suivi TEE');
    expect($spreadsheet->getSheetCount())->toBe(14)
        ->and($fiche->getCell('E21')->getValue())->toBe('CGA EXEMPLE ABIDJAN')
        ->and($fiche->getCell('B73')->getValue())->toBe(3)
        ->and($fiche->getCell('C39')->getValue())->toBe('EXEMPLE Awa')
        ->and($tee->getCell('B16')->getValue())->toBe('BOUTIQUE EXEMPLE')
        ->and($tee->getCell('B17')->getValue())->toBe('ATELIER EXEMPLE')
        ->and($tee->getCell('H17')->getValue())->toBe(8000000)
        ->and($tee->getCell('I16')->getValue())->toBe('=+J16*2')
        ->and($spreadsheet->getSheetByNameOrThrow('RESUME')->getCell('A1')->getValue())->toContain('CGA EXEMPLE ABIDJAN DU 01/07/2026 AU 30/09/2026')
        ->and(workbookDrawings($this->output))->toBe(workbookDrawings($template));

    foreach ($spreadsheet->getAllSheets() as $sheet) {
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $value = $sheet->getCell($coordinate)->getValue();
            $text = $value instanceof RichText ? $value->getPlainText() : (is_string($value) ? $value : '');
            expect($text)->not->toContain('${', "{$sheet->getTitle()}!{$coordinate}");
        }
    }
});

it('refuse de produire un classeur dont une variable n\'a pas de valeur', function () {
    $sample = app(SampleDocumentData::class)->workbook();
    unset($sample['values']['cga_name']);

    expect(fn () => app(OfficialWorkbookFiller::class)->fill(config('documents.templates.quarterly_report_xlsx.path'), $this->output, $sample['values'], $sample['rows']))
        ->toThrow(IncompleteTemplateDataException::class, 'cga_name');
    expect($this->output)->not->toBeFile();
});

it('signale aussi une variable manquante au milieu d\'un texte', function () {
    $sample = app(SampleDocumentData::class)->workbook();
    unset($sample['values']['period_end']);

    expect(fn () => app(OfficialWorkbookFiller::class)->fill(config('documents.templates.quarterly_report_xlsx.path'), $this->output, $sample['values'], $sample['rows']))
        ->toThrow(IncompleteTemplateDataException::class, 'period_end');
});
