<?php

use App\Domain\Documents\Samples\SampleDocumentData;
use App\Domain\Documents\Templates\DocxTemplateRenderer;
use App\Domain\Documents\Templates\IncompleteTemplateDataException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Settings;

/*
 * Remplissage des modèles Word (02-TRD §8.1-8.2) : variables, lignes répétées, images insérées ou
 * encadrées ; aucun document ne part avec une variable sans valeur.
 */

beforeEach(function () {
    $this->work = storage_path('framework/testing/docx-'.Str::random(8));
    File::ensureDirectoryExists($this->work);
    $this->images = app(SampleDocumentData::class)->images($this->work, 'https://cga-connect.ci/verifier/essai');
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

/**
 * @return array<string, string>
 */
function documentPart(string $docx, string $part): string
{
    $zip = new ZipArchive;
    $zip->open($docx);
    $xml = (string) $zip->getFromName($part);
    $zip->close();

    return $xml;
}

/**
 * @param  list<string>  $variables
 * @return array<string, string>
 */
function imagesFor(array $images, array $variables): array
{
    return array_intersect_key($images, array_flip($variables));
}

it('remplit le contrat d\'adhésion : plus aucune variable, signature, cachet et QR insérés', function () {
    $template = config('documents.templates.membership_contract');
    $output = $this->work.'/contrat.docx';

    app(DocxTemplateRenderer::class)->render($template['path'], $output, app(SampleDocumentData::class)->values('membership_contract'), imagesFor($this->images, $template['images']));

    $document = documentPart($output, 'word/document.xml');
    expect($document)->not->toContain('${')
        ->toContain('CGA EXEMPLE ABIDJAN')
        ->toContain('ENTREPRISE EXEMPLE')
        ->and(substr_count(documentPart($output, 'word/_rels/document.xml.rels'), 'media/'))->toBeGreaterThanOrEqual(3)
        ->and(documentPart($output, 'word/header1.xml'))->toContain('CGA EXEMPLE ABIDJAN');
});

it('échappe les valeurs pour le XML : un nom avec & ou < ne casse pas le document', function () {
    $template = config('documents.templates.membership_contract');
    $output = $this->work.'/contrat.docx';
    $values = [...app(SampleDocumentData::class)->values('membership_contract'), 'member_name' => 'DUPONT & FILS <SARL>'];

    app(DocxTemplateRenderer::class)->render($template['path'], $output, $values, imagesFor($this->images, $template['images']));

    $document = documentPart($output, 'word/document.xml');
    expect($document)->toContain('DUPONT &amp; FILS &lt;SARL&gt;')
        ->and((new DOMDocument)->loadXML($document))->toBeTrue();
});

it('remplace l\'image neutre du cadre du logo par le logo du CGA, ajusté au cadre', function () {
    $template = config('documents.templates.membership_certificate');
    $output = $this->work.'/attestation.docx';
    $placeholder = documentPart($template['path'], 'word/media/cga_logo.jpeg');

    app(DocxTemplateRenderer::class)->render($template['path'], $output, app(SampleDocumentData::class)->values('membership_certificate'), imagesFor($this->images, [...$template['images'], ...$template['framed_images']]));

    $logo = documentPart($output, 'word/media/cga_logo.jpeg');
    expect($logo)->not->toBe($placeholder)
        ->and(array_slice((array) getimagesizefromstring($logo), 0, 2))->toBe(array_slice((array) getimagesizefromstring($placeholder), 0, 2))
        ->and(documentPart($output, 'word/header1.xml'))->toContain('string="2026"')
        ->and(documentPart($output, 'word/document.xml'))->toContain('string="ENTREPRISE EXEMPLE"');
});

it('répète les lignes des tableaux du rapport (cloneRow)', function () {
    $template = config('documents.templates.quarterly_report_docx');
    $output = $this->work.'/rapport.docx';
    $data = app(SampleDocumentData::class);

    app(DocxTemplateRenderer::class)->render($template['path'], $output, $data->values('quarterly_report_docx'), imagesFor($this->images, $template['images']), $data->rows('quarterly_report_docx'));

    $document = documentPart($output, 'word/document.xml');
    expect($document)->not->toContain('${')
        ->toContain('EXEMPLE Awa')->toContain('EXEMPLE Koné')
        ->toContain('BOUTIQUE EXEMPLE')->toContain('EXEMPLE Ibrahim');
});

it('travaille dans un dossier temporaire propre au rendu, supprimé ensuite : deux workers ne se gênent pas', function () {
    config(['documents.temporary_path' => $this->work.'/phpword']);
    $template = config('documents.templates.membership_contract');

    app(DocxTemplateRenderer::class)->render($template['path'], $this->work.'/contrat.docx', app(SampleDocumentData::class)->values('membership_contract'), imagesFor($this->images, $template['images']));

    expect(File::directories($this->work.'/phpword'))->toBe([])
        ->and(Settings::getTempDir())->toBe(sys_get_temp_dir());
});

it('refuse de produire un document dont une variable n\'a pas de valeur', function () {
    $template = config('documents.templates.membership_contract');
    $values = app(SampleDocumentData::class)->values('membership_contract');
    unset($values['member_ncc']);

    expect(fn () => app(DocxTemplateRenderer::class)->render($template['path'], $this->work.'/contrat.docx', $values, imagesFor($this->images, $template['images'])))
        ->toThrow(IncompleteTemplateDataException::class, 'member_ncc');
    expect($this->work.'/contrat.docx')->not->toBeFile();
});

it('refuse un document sans le logo de son cadre', function () {
    $template = config('documents.templates.membership_certificate');

    expect(fn () => app(DocxTemplateRenderer::class)->render($template['path'], $this->work.'/attestation.docx', app(SampleDocumentData::class)->values('membership_certificate'), imagesFor($this->images, $template['images'])))
        ->toThrow(IncompleteTemplateDataException::class, 'cga_logo');
});

it('signale une image encadrée illisible', function () {
    $template = config('documents.templates.membership_certificate');
    file_put_contents($this->work.'/pas-une-image.png', 'texte');

    expect(fn () => app(DocxTemplateRenderer::class)->render($template['path'], $this->work.'/attestation.docx', app(SampleDocumentData::class)->values('membership_certificate'), [
        ...imagesFor($this->images, $template['images']), 'cga_logo' => $this->work.'/pas-une-image.png',
    ]))->toThrow(RuntimeException::class);
});

it('signale un modèle illisible', function () {
    file_put_contents($this->work.'/faux.docx', 'pas un zip');

    expect(fn () => app(DocxTemplateRenderer::class)->framedImages($this->work.'/faux.docx'))->toThrow(RuntimeException::class, 'Document illisible');
});
