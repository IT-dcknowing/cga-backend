<?php

use App\Domain\Documents\Samples\SampleDocumentData;
use App\Domain\Documents\Templates\DocxTemplateRenderer;
use App\Domain\Reports\Charts\ChartFactory;
use App\Domain\Reports\Charts\ReportChart;
use App\Support\Files\PdfConverter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Writer\Word2007\Part\Chart as ChartPart;
use Tests\Support\LibreOffice;

/*
 * Graphiques natifs du rapport modernisé (06-IMPLEMENTATION-PLAN étape 0.12, 02-TRD §8.4, décision
 * T-21) : secteurs, barres et courbe insérés dans un modèle DOCX, à la palette de la charte, puis
 * convertis en PDF par LibreOffice.
 */

beforeEach(function () {
    $this->work = storage_path('framework/testing/charts-'.Str::random(8));
    File::ensureDirectoryExists($this->work);
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

/**
 * XML d'un graphique tel que PhpWord l'écrit dans le document.
 */
function chartXml(ReportChart $chart): string
{
    Settings::setOutputEscapingEnabled(true);
    $part = new ChartPart;
    $part->setElement(app(ChartFactory::class)->make($chart));

    return $part->write();
}

describe('ReportChart', function () {
    it('refuse un graphique mal décrit', function (Closure $chart, string $message) {
        expect($chart)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'type inconnu' => [fn () => new ReportChart('radar', 'T', ['A'], ['S' => [1]]), 'Type de graphique inconnu'],
        'sans catégorie' => [fn () => new ReportChart('column', 'T', [], ['S' => []]), 'au moins une catégorie'],
        'secteurs à deux séries' => [fn () => new ReportChart('pie', 'T', ['A'], ['S' => [1], 'U' => [2]]), 'qu\'une série'],
        'série incomplète' => [fn () => new ReportChart('line', 'T', ['A', 'B'], ['S' => [1]]), 'une valeur par catégorie'],
    ]);
});

describe('ChartFactory', function () {
    it('montre les pourcentages d\'un secteur, avec une couleur de la palette par part', function () {
        $xml = chartXml(new ReportChart('pie', 'Portefeuille', ['TEE', 'RME'], ['Adhérents' => [3, 1]]));

        expect($xml)->toContain('<c:pieChart>')
            ->toContain('<c:showPercent val="1"/>')
            ->toContain('<c:showVal val="0"/>')
            ->toContain('val="1E6A4E"')->toContain('val="4E9273"')
            ->toContain('<c:legendPos val="b"/>');
    });

    it('donne à chaque série de barres sa couleur, et montre les valeurs', function () {
        $chart = new ReportChart('column', 'Adhérents', ['A', 'B'], ['Actifs' => [4, 5], 'Nouveaux' => [1, 2]], 'Nombre');
        $xml = app(ChartFactory::class)->colorSeries(chartXml($chart));

        preg_match_all('#<c:ser>.*?</c:ser>#s', $xml, $series);
        expect($series[0])->toHaveCount(2)
            ->and($series[0][0])->toContain('</c:tx><c:spPr><a:solidFill><a:srgbClr val="1E6A4E"/>')
            ->and(substr_count($series[0][0], 'val="1E6A4E"'))->toBe(3)
            ->and($series[0][1])->toContain('</c:tx><c:spPr><a:solidFill><a:srgbClr val="4E9273"/>')
            ->and($xml)->toContain('<c:showVal val="1"/>')
            ->and(explode('<c:ser>', $xml)[0])->not->toContain('<c:spPr>');
    });

    it('trace une courbe à la couleur de sa série', function () {
        $xml = app(ChartFactory::class)->colorSeries(chartXml(new ReportChart('line', 'Tendance', ['T1', 'T2'], ['Actifs' => [1, 2]])));

        expect($xml)->toContain('<c:spPr><a:ln w="28575" cap="rnd"><a:solidFill><a:srgbClr val="1E6A4E"/>');
    });

    it('laisse les secteurs tels quels', function () {
        $xml = chartXml(new ReportChart('pie', 'Portefeuille', ['TEE'], ['Adhérents' => [3]]));

        expect(app(ChartFactory::class)->colorSeries($xml))->toBe($xml);
    });

    it('échappe le titre et les noms de séries, que PhpWord écrit tels quels', function () {
        $xml = chartXml(new ReportChart('column', 'R&D <test>', ['A & B'], ['Série "1" & co' => [1]]));

        expect((new DOMDocument)->loadXML($xml))->toBeTrue()
            ->and($xml)->toContain('R&amp;D &lt;test&gt;')
            ->toContain('A &amp; B');
    });
});

it('insère les trois graphiques dans le modèle, à la place de leurs variables', function () {
    $data = app(SampleDocumentData::class);
    $output = $this->work.'/graphiques.docx';

    app(DocxTemplateRenderer::class)->render($data->chartTemplate($this->work.'/modele.docx'), $output, [], [], [], $data->charts());

    $zip = new ZipArchive;
    $zip->open($output);
    $charts = array_values(array_filter(array_map(fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1)), fn (string $name): bool => str_starts_with($name, 'word/charts/chart')));
    $document = (string) $zip->getFromName('word/document.xml');
    $types = (string) $zip->getFromName('[Content_Types].xml');
    $columns = (string) $zip->getFromName($charts[1]);
    $zip->close();

    expect($charts)->toHaveCount(3)
        ->and($document)->not->toContain('${')
        ->and(substr_count($document, '<c:chart '))->toBe(3)
        ->and(substr_count($types, 'drawingml.chart+xml'))->toBe(3)
        ->and($columns)->toContain('<c:spPr><a:solidFill><a:srgbClr val="4E9273"/>');
});

it('convertit les graphiques en PDF avec LibreOffice', function () {
    $data = app(SampleDocumentData::class);
    $output = $this->work.'/graphiques.docx';
    app(DocxTemplateRenderer::class)->render($data->chartTemplate($this->work.'/modele.docx'), $output, [], [], [], $data->charts());
    LibreOffice::prepare();

    expect(app(PdfConverter::class)->convert($output))->toStartWith('%PDF');
})->group('libreoffice');
