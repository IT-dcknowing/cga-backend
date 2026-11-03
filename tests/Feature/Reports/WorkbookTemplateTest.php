<?php

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use App\Domain\Reports\Workbook\WorkbookStructure;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\TemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\ReportGeneration;

/*
 * Classeur officiel remplaçable (06-IMPLEMENTATION-PLAN étape 5.10, RG-RPT-10, 02-TRD §8.3) : le classeur
 * provisoire est une version de modèle ; une nouvelle version du SP-CGA se dépose par l'écran des modèles
 * (3.12), avec un contrôle de structure (14 feuilles, feuilles nommées, bloc par CDI, tableaux de la
 * feuille RESUME), puis sert aux générations suivantes sans développement.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync']);
    fakePdfConverter();
    $this->admin = Membership::factory()->role(Role::PlatformAdmin)->create();
});

/**
 * Copie du classeur provisoire, transformée, déposée comme un fichier réel.
 *
 * @param  callable(Spreadsheet): void  $change
 */
function workbookVariant(callable $change): UploadedFile
{
    $book = IOFactory::load((string) config('documents.templates.quarterly_report_xlsx.path'));
    $change($book);
    $path = storage_path('framework/testing/classeur-'.uniqid().'.xlsx');
    IOFactory::createWriter($book, 'Xlsx')->save($path);

    return new UploadedFile($path, 'classeur.xlsx', null, null, true);
}

function uploadWorkbook(Membership $admin, UploadedFile $file, string $version)
{
    return inSpace($admin)->post('/api/v1/platform/document-templates', ['type' => 'quarterly_report_xlsx', 'version' => $version, 'file' => $file], ['Accept' => 'application/json']);
}

it('RG-RPT-10 · le classeur provisoire a la structure attendue par le remplissage', function () {
    expect(app(WorkbookStructure::class)->problems((string) config('documents.templates.quarterly_report_xlsx.path')))->toBe([]);
});

it('RG-RPT-10 · refuse une version dont la structure ne convient pas, motif par motif', function () {
    uploadWorkbook($this->admin, workbookVariant(static function (Spreadsheet $book): void {
        $book->removeSheetByIndex($book->getIndex($book->getSheetByNameOrThrow('NvxRME')));
        $sheet = $book->getSheetByNameOrThrow('Suivi TEE');
        foreach ($sheet->getRowIterator() as $row) {
            if (mb_strtoupper(trim((string) $sheet->getCell('A'.$row->getRowIndex())->getValue())) === 'TOTAL') {
                $sheet->setCellValue('A'.$row->getRowIndex(), 'Sous-total');
            }
        }
        $summary = $book->getSheetByNameOrThrow('RESUME');
        foreach ($summary->getRowIterator(21) as $row) {
            if (mb_strtoupper(trim((string) $summary->getCell('A'.$row->getRowIndex())->getValue())) === 'TOTAL') {
                $summary->setCellValue('A'.$row->getRowIndex(), 'Ensemble');
            }
        }
    }), '2026.1')->assertUnprocessable()->assertJsonValidationErrors(['file' => [
        __('report.workbook.sheet_count', ['expected' => 14, 'found' => 13]),
    ]])->assertJsonPath('errors.file', [
        __('report.workbook.sheet_count', ['expected' => 14, 'found' => 13]),
        __('report.workbook.missing_sheet', ['sheet' => 'NvxRME']),
        __('report.workbook.missing_block', ['sheet' => 'Suivi TEE']),
        __('report.workbook.missing_summary_total', ['sheet' => 'RESUME', 'table' => 3, 'row' => 21]),
    ]);

    expect(DocumentTemplate::query()->where('type', DocumentType::QuarterlyReportXlsx)->count())->toBe(1);
});

it('RG-RPT-10 · une nouvelle version du classeur, déposée puis activée, sert aux générations suivantes sans développement', function () {
    $id = uploadWorkbook($this->admin, workbookVariant(static function (Spreadsheet $book): void {
        $book->getSheetByNameOrThrow('Page de garde')->setCellValue('A1', 'Version officielle du SP-CGA');
    }), '2026.1')->assertCreated()->assertJsonPath('data.check.valid', true)->json('data.id');
    inSpace($this->admin)->postJson("/api/v1/platform/document-templates/{$id}/activate", ['approved_by_cncga_on' => today()->toDateString()])
        ->assertOk()->assertJsonPath('data.is_active', true);

    $this->travelTo('2026-10-20 10:00:00');
    $manager = ReportGeneration::centre();
    $report = ReportGeneration::report($manager);
    inSpace($manager)->postJson("/api/v1/cga/quarterly-reports/{$report->id}/generate")->assertStatus(202);

    $version = Tenancy::bypass(static fn () => QuarterlyReportVersion::query()->where('report_id', $report->id)->sole(), 'Test.');
    $document = Tenancy::bypass(static fn () => GeneratedDocument::query()->with('xlsx')->findOrFail($version->xlsx_document_id), 'Test.');
    $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
    file_put_contents($path, app(StorageService::class)->contents($document->xlsx));

    expect($document->template_id)->toBe($id)
        ->and(IOFactory::load($path)->getSheetByNameOrThrow('Page de garde')->getCell('A1')->getValue())->toBe('Version officielle du SP-CGA');
});
