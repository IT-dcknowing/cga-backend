<?php

use App\Domain\Documents\Templates\DocxTemplateRenderer;
use App\Support\Files\PdfConverter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * php artisan documents:samples (étape 0.11) : échantillons de chaque modèle, avec des données fictives,
 * pour le contrôle visuel de la QA.
 */

beforeEach(function () {
    $this->work = storage_path('framework/testing/samples-'.Str::random(8));
    app()->instance(PdfConverter::class, new class implements PdfConverter
    {
        public function convert(string $sourcePath): string
        {
            return '%PDF-1.7 '.basename($sourcePath);
        }
    });
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

it('produit chaque modèle rempli, en DOCX ou XLSX et en PDF', function () {
    $this->artisan('documents:samples', ['--output' => $this->work])
        ->expectsOutputToContain('Attestation d\'adhésion')
        ->assertSuccessful();

    foreach (['membership_contract.docx', 'membership_certificate.docx', 'quarterly_report_docx.docx', 'quarterly_report_xlsx.xlsx', 'report_charts.docx'] as $file) {
        expect($this->work.'/'.$file)->toBeFile()
            ->and((string) file_get_contents($this->work.'/'.pathinfo($file, PATHINFO_FILENAME).'.pdf'))->toBe('%PDF-1.7 '.$file);
    }
    expect($this->work.'/images/qr.png')->toBeFile()
        ->and($this->work.'/report_charts_template.docx')->not->toBeFile();
});

it('peut se passer de la conversion PDF', function () {
    $this->artisan('documents:samples', ['--output' => $this->work, '--without-pdf' => true])->assertSuccessful();

    expect(File::glob($this->work.'/*.pdf'))->toBe([]);
});

it('journalise un échec et le relance', function () {
    $failing = Mockery::mock(DocxTemplateRenderer::class);
    $failing->shouldReceive('render')->andThrow(new RuntimeException('modèle illisible'));
    app()->instance(DocxTemplateRenderer::class, $failing);
    Log::spy();

    expect(fn () => $this->artisan('documents:samples', ['--output' => $this->work])->run())->toThrow(RuntimeException::class, 'modèle illisible');
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Échantillons'));
});
