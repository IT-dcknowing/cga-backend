<?php

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Members\Models\Member;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Reports\Composers\QuarterlyReportModernComposer;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use App\Domain\Users\Models\Membership;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\ReportGeneration;

/*
 * Rapport trimestriel modernisé (06-IMPLEMENTATION-PLAN étape 5.11, RG-RPT-09 à 12, 02-TRD §8.4) : même
 * objet de données que le classeur de compatibilité, donc mêmes agrégats ; seul le portefeuille CGA ;
 * graphiques natifs à la place des variables ; synthèse chiffrée pré-rédigée ; source des données.
 * Centre de ReportGeneration : TEE à Adjamé (30 000 dus, 20 000 payés), TEE et RME à Plateau.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync']);
    fakePdfConverter();
    $this->travelTo('2026-10-20 10:00:00');
    $this->manager = ReportGeneration::centre();
    $this->report = ReportGeneration::report($this->manager);
});

function modernVersion(Membership $manager, QuarterlyReport $report): QuarterlyReportVersion
{
    inSpace($manager)->postJson("/api/v1/cga/quarterly-reports/{$report->id}/generate")->assertStatus(202);

    return Tenancy::bypass(static fn () => QuarterlyReportVersion::query()->where('report_id', $report->id)->sole(), 'Test.');
}

/**
 * Fichier produit d'un document (docx ou xlsx), copié dans un fichier temporaire.
 */
function producedFile(string $documentId, string $kind): string
{
    $document = Tenancy::bypass(static fn () => GeneratedDocument::query()->with($kind)->findOrFail($documentId), 'Test.');
    $path = tempnam(sys_get_temp_dir(), 'rpt').'.'.$kind;
    file_put_contents($path, app(StorageService::class)->contents($document->{$kind}));

    return $path;
}

/**
 * Valeurs calculées d'une colonne sur les lignes TOTAL d'une feuille du classeur, dans l'ordre.
 *
 * @return list<int|float>
 */
function workbookTotals(Spreadsheet $book, string $name, string $column): array
{
    $sheet = $book->getSheetByNameOrThrow($name);
    $totals = [];
    for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
        if (mb_strtoupper(trim((string) $sheet->getCell('A'.$row)->getValue())) === 'TOTAL') {
            $totals[] = $sheet->getCell($column.$row)->getCalculatedValue();
        }
    }

    return $totals;
}

/**
 * Parties du DOCX produit : liste des graphiques et corps du document.
 *
 * @return array{charts: list<string>, body: string}
 */
function modernParts(string $documentId): array
{
    $zip = new ZipArchive;
    $zip->open(producedFile($documentId, 'docx'));
    $names = array_map(static fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    $body = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    return ['charts' => array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'word/charts/chart'))), 'body' => $body];
}

it('RG-RPT-09 · le rapport modernisé reprend les agrégats du classeur : même objet de données, mêmes chiffres', function () {
    $version = modernVersion($this->manager, $this->report);
    $document = storedDocument((string) $version->docx_document_id);
    $content = app(QuarterlyReportModernComposer::class)->compose($document);
    $book = IOFactory::load(producedFile((string) $version->xlsx_document_id, 'xlsx'));
    // RESUME : actifs, résiliés, nouveaux ; TEE en colonne B, RME en colonne E.
    [$tee, $rme] = [workbookTotals($book, 'RESUME', 'B'), workbookTotals($book, 'RESUME', 'E')];

    expect($document->type)->toBe(DocumentType::QuarterlyReportModern)
        ->and($version->pdf_document_id)->toBe($document->id)
        ->and([$content->values['active_tee'], $content->values['active_rme'], $content->values['terminated_tee'], $content->values['terminated_rme'], $content->values['new_tee'], $content->values['new_rme']])
        ->toBe(array_map(static fn (int|float $value): string => (string) $value, [$tee[0], $rme[0], $tee[1], $rme[1], $tee[2], $rme[2]]))
        ->and($content->values['active_total'])->toBe('3')
        ->and($content->values['tee_due_total'])->toBe(DocumentFormat::amount((int) array_sum(workbookTotals($book, 'Suivi TEE', 'P'))))
        ->and($content->values['tee_paid_total'])->toBe(DocumentFormat::amount((int) array_sum(workbookTotals($book, 'Suivi TEE', 'Q'))))
        ->and($content->values['tee_due_total'])->toBe(DocumentFormat::amount(30_000))
        ->and($content->values['tee_paid_total'])->toBe(DocumentFormat::amount(20_000))
        ->and($content->values['rme_total'])->toBe(DocumentFormat::amount((int) array_sum(workbookTotals($book, 'Suivi RME', 'S'))))
        ->and(array_column($content->rows['tee_office'], 'tee_office'))->toBe(['CDI Adjamé', 'CDI Plateau'])
        ->and($content->rows['tee_office'][0]['tee_paid'])->toBe(DocumentFormat::amount(20_000))
        ->and(array_column($content->rows['summary_office'], 'summary_office'))->toBe(['CDI Adjamé', 'CDI Plateau'])
        ->and($content->rows['rme_nature'])->toHaveCount(count((array) config('reports.rme_columns')))
        ->and($content->charts)->toHaveCount(7)
        ->and($content->charts['chart_regimes']->series)->toBe([__('report.charts.members') => [2, 1]])
        ->and($content->charts['chart_tee_recovery']->series)->toBe([__('report.charts.due') => [30_000, 0], __('report.charts.paid') => [20_000, 0]]);
});

it('RG-RPT-11 · aucun dossier Cabinet dans le rapport modernisé, ses tableaux ni ses graphiques', function () {
    $cocody = TaxOffice::factory()->create(['name' => 'CDI Cocody']);
    Member::factory()->active()->cabinet()->create([
        'organization_id' => $this->manager->organization_id, 'company_name' => 'CLIENT CABINET COCODY', 'tax_office_id' => $cocody->id, 'cga_contract_signed_on' => null,
    ]);

    $version = modernVersion($this->manager, $this->report);
    ['body' => $body] = modernParts((string) $version->docx_document_id);

    expect($body)->not->toContain('CABINET COCODY')->not->toContain('CDI Cocody')
        ->and($version->snapshot['states']['sheets']['summary']['totals']['active_total'])->toBe(3)
        ->and(array_column($version->snapshot['trend'], 'total'))->toBe([0, 3, 3, 3])
        ->and($version->snapshot['charts']['chart_tax_offices']['categories'])->toBe(['CDI Adjamé', 'CDI Plateau']);
});

it('RG-RPT-09 · graphiques natifs à la place de leurs variables, aucune variable restante, PDF produit', function () {
    $version = modernVersion($this->manager, $this->report);
    ['charts' => $charts, 'body' => $body] = modernParts((string) $version->docx_document_id);
    $document = storedDocument((string) $version->docx_document_id);

    expect($charts)->toHaveCount(7)
        ->and(substr_count($body, '<c:chart '))->toBe(7)
        ->and($body)->not->toContain('${')
        ->toContain('Trimestre stable.')
        ->toContain('CDI Adjamé')
        ->toContain('CDI Plateau')
        ->and($document->pdf_file_id)->not->toBeNull()
        ->and($document->template?->type)->toBe(DocumentType::QuarterlyReportModern);
});

it('RG-RPT-09 · synthèse chiffrée pré-rédigée : proposée par la prévisualisation, gardée dans l\'instantané, modifiable par le CGA', function () {
    $expected = implode(' ', [
        __('report.synthesis.portfolio', ['date' => '30 septembre 2026', 'total' => 3, 'tee' => 2, 'rme' => 1, 'variation' => __('report.synthesis.variation_none')]),
        __('report.synthesis.movements', ['new' => 0, 'terminated' => 0]),
        __('report.synthesis.tee', ['paid' => DocumentFormat::amount(20_000), 'due' => DocumentFormat::amount(30_000), 'rate' => DocumentFormat::rate(66.7)]),
        __('report.synthesis.rme', ['total' => DocumentFormat::amount(0)]),
        __('report.synthesis.closing', ['smt' => 1, 'balance_sheets' => 1]),
    ]);

    inSpace($this->manager)->getJson("/api/v1/cga/quarterly-reports/{$this->report->id}/preview")->assertOk()
        ->assertJsonPath('data.synthesis', $expected)
        ->assertJsonPath('data.trend.3.label', 'T3 2026')
        ->assertJsonPath('data.trend.0.label', 'T4 2025');

    inSpace($this->manager)->patchJson("/api/v1/cga/quarterly-reports/{$this->report->id}", ['qualitative_analysis' => ['synthesis' => 'Synthèse revue par la gérante.']])->assertOk();
    $version = modernVersion($this->manager, $this->report);

    expect($version->snapshot['synthesis'])->toBe($expected)
        ->and(modernParts((string) $version->docx_document_id)['body'])->toContain('Synthèse revue par la gérante.');
});

it('RG-RPT-09 · sans hausse ni effectif précédent, la synthèse le dit ; un trimestre sans TEE exigible aussi', function () {
    $this->travelTo('2026-04-20 10:00:00');
    $id = inSpace($this->manager)->postJson('/api/v1/cga/quarterly-reports', ['year' => 2026, 'quarter' => 1])->assertCreated()->json('data.id');

    $synthesis = (string) inSpace($this->manager)->getJson("/api/v1/cga/quarterly-reports/{$id}/preview")->assertOk()->json('data.synthesis');

    expect($synthesis)->toContain((string) __('report.synthesis.variation_up', ['count' => 3]))
        ->toContain((string) __('report.synthesis.tee_none'))
        ->toContain((string) __('report.synthesis.movements', ['new' => 3, 'terminated' => 0]));
});

it('RG-RPT-09 · un dossier sorti du portefeuille CGA dans le trimestre fait baisser l\'effectif de la synthèse', function () {
    // Au portefeuille CGA depuis 2025, basculé au Cabinet le 15/08/2026 : actif au T2, plus au T3.
    Member::factory()->active()->cabinet()->create([
        'organization_id' => $this->manager->organization_id, 'company_name' => 'BASCULE AOUT', 'tax_office_id' => TaxOffice::factory()->create()->id,
        'adhesion_date' => '2025-01-01', 'cga_contract_signed_on' => '2025-01-01', 'cga_contract_ended_on' => '2026-08-15',
    ]);

    $preview = inSpace($this->manager)->getJson("/api/v1/cga/quarterly-reports/{$this->report->id}/preview")->assertOk();

    expect(array_column((array) $preview->json('data.trend'), 'total'))->toBe([1, 4, 4, 3])
        ->and((string) $preview->json('data.synthesis'))->toContain((string) __('report.synthesis.variation_down', ['count' => 1]));
});

it('RG-RPT-12 · la source de chaque donnée figure au rapport modernisé', function () {
    $version = modernVersion($this->manager, $this->report);
    $content = app(QuarterlyReportModernComposer::class)->compose(storedDocument((string) $version->docx_document_id));

    expect($content->values['data_sources'])->toBe((string) __('report.sources.text', [
        'revenues' => __('report.sources.none'),
        'payments' => __('report.sources.item', ['source' => __('report.sources.kinds.cga_entry'), 'count' => 1]),
    ]));
});
