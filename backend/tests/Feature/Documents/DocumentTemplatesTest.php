<?php

use App\Domain\Documents\Templates\DocxTemplateRenderer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/*
 * Modèles de documents de la maquette (06-IMPLEMENTATION-PLAN étape 0.11, 02-TRD §8.1, §8.3) :
 * nettoyés de toute donnée réelle et balisés ${…}, avec exactement les variables déclarées dans
 * config/documents.php.
 */

/**
 * Textes de la maquette d'origine qui ne doivent plus apparaître nulle part dans les modèles, pas même
 * dans un attribut, une propriété du document ou une image décrite : centre, gérant, adhérents,
 * salariés, numéros et coordonnées réels.
 *
 * @return list<string>
 */
function realDataOfMockup(): array
{
    return [
        'KNOWING', 'KEYMAN', 'DCK', 'IVORIAN', 'DIABY', 'INC SARL', 'I.N.C', 'COMO', 'FOTO', 'KOMENAN', 'KOUAM', 'GNAGBO',
        'YOHOU', 'YAGUERA', 'ASIRU', 'DJIGUIFORO', 'AMANI', 'YIRA', 'COTRADEC', 'CERCI', 'HAXEDYN', 'PURE DRINK', 'HADRIEL',
        '1864699', '1444351', '1827528', '1942341', 'CI-ABJ-2018', 'CI-ABJ-2014', '296/SEPMBPE', 'BTP0205',
        '59 76 72 88', '72 92 30 68', '22 42 14 43', '08 25 22 25', '22421443', 'dcknowing',
        'Plateaux', 'PLATEAUX', 'PLATAEUX', 'Plaeaux', 'RIVIERA', 'BINGERVILLE', 'Logo DCK', 'DS CF', '2019', 'Utilisateur Windows',
    ];
}

dataset('modèles', ['membership_contract', 'membership_certificate', 'receipt', 'invoice', 'credit_note', 'radiation_notice', 'quarterly_report_docx', 'quarterly_report_xlsx']);

it('déclare chaque modèle de la liste', function () {
    expect(array_keys(config('documents.templates')))->toBe(['membership_contract', 'membership_certificate', 'receipt', 'invoice', 'credit_note', 'radiation_notice', 'quarterly_report_docx', 'quarterly_report_xlsx']);
});

it('ne contient aucune donnée réelle de la maquette, même dans un attribut ou les propriétés', function (string $type) {
    $path = config("documents.templates.{$type}.path");
    expect($path)->toBeFile();

    foreach (templateParts($path) as $part => $xml) {
        foreach (realDataOfMockup() as $real) {
            expect(str_contains($xml, $real))->toBeFalse("« {$real} » reste dans {$part} de {$type}");
        }
    }
})->with('modèles');

it('n\'a ni commentaire de relecture ni auteur', function (string $type) {
    foreach (templateParts(config("documents.templates.{$type}.path")) as $part => $xml) {
        expect($xml)->not->toMatch('/w:author="[^"]+"/')
            ->and($xml)->not->toMatch('/<dc:creator>(?!CGA Connect<)[^<]+</', $part);
    }
})->with('modèles');

it('porte exactement les variables déclarées (modèles Word)', function (string $type) {
    $template = config("documents.templates.{$type}");
    $expected = [...$template['variables'], ...$template['images'], ...array_merge([], ...array_values($template['rows']))];
    sort($expected);

    expect(app(DocxTemplateRenderer::class)->variables($template['path']))->toBe(array_values(array_unique($expected)))
        ->and(array_keys(app(DocxTemplateRenderer::class)->framedImages($template['path'])))->toBe($template['framed_images'] ?? []);
})->with(['membership_contract', 'membership_certificate', 'receipt', 'invoice', 'credit_note', 'radiation_notice', 'quarterly_report_docx']);

it('RG-DOC-02 ne porte jamais la mention « signature électronique »', function (string $type) {
    foreach (templateParts(config("documents.templates.{$type}.path")) as $xml) {
        expect(mb_strtolower(strip_tags($xml)))->not->toContain('électroniquement')->not->toContain('signature électronique');
    }
})->with('modèles');

it('garde les 14 feuilles du classeur officiel et ses formes dessinées, avec les variables déclarées', function () {
    $template = config('documents.templates.quarterly_report_xlsx');
    $spreadsheet = IOFactory::load($template['path']);

    $found = [];
    foreach ($spreadsheet->getAllSheets() as $sheet) {
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $value = $sheet->getCell($coordinate)->getValue();
            $text = $value instanceof RichText ? $value->getPlainText() : (is_string($value) ? $value : '');
            preg_match_all('/\$\{([a-z0-9_]+)\}/', $text, $matches);
            array_push($found, ...$matches[1]);
        }
    }
    $found = array_values(array_unique($found));
    sort($found);
    $expected = $template['variables'];
    sort($expected);

    expect($spreadsheet->getSheetCount())->toBe($template['sheets'])
        ->and($found)->toBe($expected)
        ->and(array_keys(array_filter(templateParts($template['path']), fn (string $part): bool => str_starts_with($part, 'xl/drawings/drawing'), ARRAY_FILTER_USE_KEY)))
        ->toHaveCount(3);
});

it('ne garde aucun résultat de formule calculé sur les données réelles : tout est recalculé à l\'ouverture', function () {
    $parts = templateParts(config('documents.templates.quarterly_report_xlsx.path'));

    expect($parts['xl/workbook.xml'])->toContain('fullCalcOnLoad="1"');
    foreach ($parts as $name => $xml) {
        if (str_starts_with($name, 'xl/worksheets/sheet')) {
            expect($xml)->not->toMatch('#</f><v>#');
        }
    }
});
