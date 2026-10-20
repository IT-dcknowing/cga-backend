<?php

use App\Domain\Documents\Samples\SampleDocumentData;
use App\Domain\Documents\Templates\DocxTemplateRenderer;
use App\Support\Config\SettingsService;
use App\Support\Files\LibreOfficePdfConverter;
use App\Support\Files\PdfConverter;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\LibreOffice;

/*
 * Conversion DOCX → PDF par LibreOffice (02-TRD §8.2) : profil par worker, délai maximal
 * pdf.timeout_seconds, dossier de travail jetable ; un échec devient une ExternalServiceException.
 */

beforeEach(function () {
    $this->work = storage_path('framework/testing/pdf-'.Str::random(8));
    File::ensureDirectoryExists($this->work);
    $this->source = $this->work.'/document.docx';
    file_put_contents($this->source, 'docx');
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

/**
 * Argument qui suit une option de la ligne de commande simulée.
 *
 * @param  list<string>  $command
 */
function optionOf(array $command, string $option): string
{
    return $command[array_search($option, $command, true) + 1];
}

it('convertit un document rempli en PDF avec LibreOffice, sans laisser de fichier de travail', function () {
    $template = config('documents.templates.membership_contract');
    $data = app(SampleDocumentData::class);
    $docx = $this->work.'/contrat.docx';
    app(DocxTemplateRenderer::class)->render($template['path'], $docx, $data->values('membership_contract'), array_intersect_key($data->images($this->work, 'https://cga-connect.ci/verifier/essai'), array_flip($template['images'])));
    config(['pdf.work_path' => $this->work.'/conversions']);
    LibreOffice::prepare();

    $pdf = app(PdfConverter::class)->convert($docx);

    expect($pdf)->toStartWith('%PDF')
        ->and(strlen($pdf))->toBeGreaterThan(10_000)
        ->and(File::directories($this->work.'/conversions'))->toBe([]);
})->group('libreoffice');

it('lance LibreOffice sans interface, avec le profil du worker et le délai du paramètre', function () {
    config(['pdf.libreoffice.profile_slot' => '03', 'pdf.libreoffice.profiles_path' => 'C:\\Dossier Test\\libreoffice']);
    app(SettingsService::class)->set('pdf.timeout_seconds', 45);
    Process::fake(function (PendingProcess $process) {
        file_put_contents(optionOf($process->command, '--outdir').'/document.pdf', '%PDF-1.7 essai');

        return Process::result();
    });

    expect(app(PdfConverter::class)->convert($this->source))->toBe('%PDF-1.7 essai');

    Process::assertRan(fn (PendingProcess $process): bool => $process->timeout === 45
        && in_array('--headless', $process->command, true)
        && in_array('-env:UserInstallation=file:///C:/Dossier%20Test/libreoffice/profile-03', $process->command, true)
        && optionOf($process->command, '--convert-to') === 'pdf');
});

it('prend un profil par processus sans numéro de worker valable', function () {
    config(['pdf.libreoffice.profile_slot' => '../../etc', 'pdf.libreoffice.profiles_path' => '/var/cga/libreoffice']);

    expect(app(LibreOfficePdfConverter::class)->profileDirectory())->toBe('/var/cga/libreoffice/profile-pid-'.getmypid());
});

it('signale une conversion en échec avec la sortie de LibreOffice', function () {
    Process::fake(fn () => Process::result(output: '', errorOutput: 'Error: source file could not be loaded', exitCode: 1));

    try {
        app(PdfConverter::class)->convert($this->source);
        $this->fail('La conversion aurait dû échouer.');
    } catch (ExternalServiceException $exception) {
        expect($exception->service)->toBe('libreoffice')
            ->and($exception->providerResponse)->toContain('could not be loaded');
    }
});

it('signale une conversion qui ne produit pas de PDF', function () {
    Process::fake(function (PendingProcess $process) {
        file_put_contents(optionOf($process->command, '--outdir').'/document.pdf', 'pas un pdf');

        return Process::result();
    });

    expect(fn () => app(PdfConverter::class)->convert($this->source))->toThrow(ExternalServiceException::class);
});

it('arrête une conversion trop longue', function () {
    Process::fake(fn () => throw new ProcessTimedOutException(
        new SymfonyTimeout(new SymfonyProcess(['soffice']), SymfonyTimeout::TYPE_GENERAL),
        new FakeProcessResult,
    ));

    try {
        app(PdfConverter::class)->convert($this->source);
        $this->fail('La conversion aurait dû être arrêtée.');
    } catch (ExternalServiceException $exception) {
        expect($exception->providerResponse)->toBe('Délai de conversion dépassé.')
            ->and($exception->getPrevious())->toBeInstanceOf(ProcessTimedOutException::class);
    }
});

it('refuse un fichier introuvable', function () {
    expect(fn () => app(PdfConverter::class)->convert($this->work.'/absent.docx'))->toThrow(ExternalServiceException::class);
});

it('refuse un pilote de conversion inconnu', function () {
    config(['pdf.driver' => 'inconnu']);

    expect(fn () => app(PdfConverter::class))->toThrow(InvalidArgumentException::class, 'inconnu');
});
