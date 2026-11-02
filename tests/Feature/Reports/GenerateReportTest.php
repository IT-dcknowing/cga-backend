<?php

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Reports\Composers\ReportSnapshot;
use App\Domain\Reports\Enums\CgaResourceCategory;
use App\Domain\Reports\Models\CgaPerson;
use App\Domain\Reports\Models\CgaResource;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/*
 * Génération du rapport trimestriel (06-IMPLEMENTATION-PLAN étape 5.4, F-RPT-03, RG-RPT-02, RG-RPT-05,
 * RG-RPT-08, RG-RPT-10) : objet de données unique enregistré dans la version, rapport Word et PDF,
 * classeur officiel de 14 feuilles rempli par CDI sans variable restante, nouvelle génération avant
 * transmission, droits.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync']);
    fakePdfConverter();
    $this->travelTo('2026-10-20 10:00:00');
    $this->manager = generationCentre();
    $this->report = generationReport($this->manager);
});

function generationCentre(): Membership
{
    $cga = Organization::factory()->profileComplete()->create(['numbering_abbreviation' => 'GEN', 'said' => 'SAID Plateau', 'history_text' => 'Créé en 2004.', 'previous_year_revenue' => 48_000_000]);
    $manager = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $cga->id]);
    CgaPerson::factory()->create(['organization_id' => $cga->id, 'full_name' => 'Awa Koné']);
    foreach (CgaResourceCategory::cases() as $category) {
        CgaResource::factory()->create(['organization_id' => $cga->id, 'category' => $category, 'quantity' => 2]);
    }

    // Portefeuille : un TEE à Adjamé (30 000 dus au trimestre, 20 000 payés), un TEE et un RME à Plateau.
    [$adjame, $plateau] = [TaxOffice::factory()->create(['name' => 'CDI Adjamé']), TaxOffice::factory()->create(['name' => 'CDI Plateau'])];
    $tee = Member::factory()->active()->create(['organization_id' => $cga->id, 'company_name' => 'BOUTIQUE ADJAME', 'tax_office_id' => $adjame->id, 'land_section' => 'S1', 'land_parcel' => 'P2']);
    Member::factory()->active()->create(['organization_id' => $cga->id, 'company_name' => 'ATELIER PLATEAU', 'tax_office_id' => $plateau->id]);
    Member::factory()->active()->create(['organization_id' => $cga->id, 'company_name' => 'SARL RME PLATEAU', 'regime' => TaxRegime::Rme, 'tax_office_id' => $plateau->id]);
    $instance = ObligationInstance::factory()->create([
        'member_obligation_id' => MemberObligation::factory()->create(['member_id' => $tee->id])->id, 'kind' => InstanceKind::Payment, 'tax_nature' => 'tee',
        'period_label' => '2026-T3', 'period_start' => '2026-07-01', 'period_end' => '2026-09-30', 'due_date' => '2026-09-15',
        'amount_gross' => 60_000, 'amount_due' => 30_000, 'months_covered' => 3, 'status' => InstanceStatus::PartiallyPaid,
    ]);
    DB::table('obligation_payments')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $cga->id, 'instance_id' => $instance->id, 'member_id' => $tee->id, 'tax_nature' => 'tee',
        'amount' => 20_000, 'paid_on' => '2026-09-10', 'source' => 'cga_entry', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $manager;
}

function generationReport(Membership $manager): QuarterlyReport
{
    $id = inSpace($manager)->postJson('/api/v1/cga/quarterly-reports', ['year' => 2026, 'quarter' => 3])->assertCreated()->json('data.id');
    $director = Tenancy::bypass(static fn () => CgaPerson::query()->where('organization_id', $manager->organization_id)->firstOrFail(), 'Test.');
    $content = [];
    foreach ((array) config('reports.content') as $section) {
        foreach ($section as $key => $definition) {
            $content[$key] = $definition['type'] === 'integer' ? 1 : 'Néant';
        }
    }
    inSpace($manager)->patchJson("/api/v1/cga/quarterly-reports/{$id}", [
        'content' => [...$content, 'trainings' => 'Deux sessions FNE'],
        'people_taxes' => [['person_id' => $director->id, 'its' => 120_000, 'igr' => 45_000, 'cnps' => 60_000]],
        'qualitative_analysis' => ['synthesis' => 'Trimestre stable.'],
        'report_date' => '2026-10-20',
        'regional_directorate_label' => 'DR Abidjan Nord',
    ])->assertOk()->assertJsonPath('data.can.generate', true);

    return Tenancy::bypass(static fn () => QuarterlyReport::query()->findOrFail($id), 'Test.');
}

function generationUrl(QuarterlyReport $report, string $suffix = ''): string
{
    return "/api/v1/cga/quarterly-reports/{$report->id}{$suffix}";
}

function reportVersion(QuarterlyReport $report, int $number = 1): QuarterlyReportVersion
{
    return Tenancy::bypass(static fn () => QuarterlyReportVersion::query()->with(['pdfDocument', 'xlsxDocument'])->where('report_id', $report->id)->where('version', $number)->firstOrFail(), 'Test.');
}

function workbookOf(QuarterlyReportVersion $version): Spreadsheet
{
    $document = Tenancy::bypass(static fn () => GeneratedDocument::query()->with('xlsx')->findOrFail($version->xlsx_document_id), 'Test.');
    $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
    file_put_contents($path, app(StorageService::class)->contents($document->xlsx));

    return IOFactory::createReader('Xlsx')->load($path);
}

/**
 * @return list<string>
 */
function sheetTexts(Spreadsheet $book, string $sheet): array
{
    $texts = [];
    foreach ($book->getSheetByNameOrThrow($sheet)->toArray(null, false, false) as $row) {
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                $texts[] = (string) $value;
            }
        }
    }

    return $texts;
}

it('RG-RPT-05 · génère la V1 : instantané de l\'objet de données, rapport Word et PDF, classeur de compatibilité', function () {
    inSpace($this->manager)->postJson(generationUrl($this->report, '/generate'))
        ->assertStatus(202)->assertJsonPath('data.status', 'generated')->assertJsonPath('data.current_version', 1)->assertJsonPath('data.can.transmit', true);

    $version = reportVersion($this->report);
    expect(array_keys($version->snapshot))->toEqualCanonicalizing(['report', 'cga', 'people', 'resources', 'content', 'qualitative_analysis', 'states', 'increase_rule'])
        ->and($version->snapshot['increase_rule'])->toEqual(['basis' => 'annual_due', 'min_increase_pct' => 0, 'amount' => 'net'])
        ->and($version->snapshot['people']['directors'][0]['taxes'])->toEqual(['its' => 120_000, 'igr' => 45_000, 'cnps' => 60_000])
        ->and($version->pdfDocument)->status->toBe(DocumentStatus::Valid)->number->toBe('GEN-RT-2026-T3')->visible_to_member->toBeFalse()
        ->and($version->pdfDocument->pdf_file_id)->not->toBeNull()
        ->and($version->xlsxDocument)->status->toBe(DocumentStatus::Valid)
        ->and($version->docxDocument->is($version->pdfDocument))->toBeTrue();

    inSpace($this->manager)->getJson(generationUrl($this->report, '/versions'))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.version', 1)
        ->assertJsonPath('data.0.documents.report.status', 'valid')
        ->assertJsonPath('data.0.documents.slip', null)
        ->assertJsonMissingPath('data.0.snapshot');
    expect(inSpace($this->manager)->getJson(generationUrl($this->report, '/versions'))->json('data.0.documents.workbook.files.xlsx'))->toContain('signature=');
});

it('RG-RPT-10 · classeur officiel : 14 feuilles, un bloc par CDI, totaux, Résumé, aucune variable restante', function () {
    inSpace($this->manager)->postJson(generationUrl($this->report, '/generate'))->assertStatus(202);
    $book = workbookOf(reportVersion($this->report));
    $tee = sheetTexts($book, 'Suivi TEE');

    expect($book->getSheetCount())->toBe(14)
        ->and(collect($book->getAllSheets())->flatMap(fn ($sheet) => sheetTexts($book, $sheet->getTitle()))->filter(fn (string $text): bool => str_contains($text, '${'))->all())->toBe([])
        ->and($tee)->toContain('CDI CDI Adjamé', 'CDI CDI Plateau', 'BOUTIQUE ADJAME', 'ATELIER PLATEAU')
        ->and($tee)->not->toContain('SARL RME PLATEAU')
        ->and(sheetTexts($book, 'Suivi RME'))->toContain('SARL RME PLATEAU')
        ->and(sheetTexts($book, 'Page de garde'))->toContain('TROISIÈME TRIMESTRE 2026')
        ->and(sheetTexts($book, 'Fiche tech CGA'))->toContain('Awa Koné', 'SAID Plateau', 'Deux sessions FNE');

    // Bloc d'Adjamé : une ligne (TEE trimestre : 60 000 brut, 30 000 nets, 20 000 payés), puis TOTAL.
    $sheet = $book->getSheetByNameOrThrow('Suivi TEE');
    $row = collect(range(1, $sheet->getHighestRow()))->first(fn (int $r): bool => $sheet->getCell('B'.$r)->getValue() === 'BOUTIQUE ADJAME');
    expect([$sheet->getCell('O'.$row)->getValue(), $sheet->getCell('P'.$row)->getValue(), $sheet->getCell('Q'.$row)->getValue()])->toBe([60_000, 30_000, 20_000])
        ->and($sheet->getCell('A'.($row + 1))->getValue())->toBe('TOTAL')
        ->and($sheet->getCell('P'.($row + 1))->getValue())->toBe("=SUM(P{$row}:P{$row})")
        ->and($sheet->getCell('P'.($row + 1))->getCalculatedValue())->toBe(30_000);

    // Résumé : une ligne par CDI (Adjamé : 1 TEE ; Plateau : 1 TEE et 1 RME).
    $summary = $book->getSheetByNameOrThrow('RESUME');
    expect([$summary->getCell('A5')->getValue(), $summary->getCell('B5')->getValue(), $summary->getCell('A6')->getValue(), $summary->getCell('B6')->getValue(), $summary->getCell('E6')->getValue()])
        ->toBe(['CDI Adjamé', 1, 'CDI Plateau', 1, 1])
        ->and($summary->getCell('B7')->getCalculatedValue())->toBe(2);
});

it('RG-RPT-02 · refuse un rapport incomplet ou une fiche du centre incomplète', function () {
    inSpace($this->manager)->patchJson(generationUrl($this->report), ['content' => ['trainings' => null]])->assertOk();
    inSpace($this->manager)->postJson(generationUrl($this->report, '/generate'))
        ->assertStatus(409)->assertJsonPath('code', 'report_incomplete')->assertJsonPath('details.completeness.section_4', 92);

    inSpace($this->manager)->patchJson(generationUrl($this->report), ['content' => ['trainings' => 'Néant']])->assertOk();
    Tenancy::bypass(fn () => $this->manager->organization->forceFill(['rccm' => null])->save(), 'Test.');
    inSpace($this->manager)->postJson(generationUrl($this->report, '/generate'))->assertStatus(409)->assertJsonPath('code', 'cga_profile_incomplete');
    expect(Tenancy::bypass(fn () => QuarterlyReportVersion::query()->count(), 'Test.'))->toBe(0);
});

it('RG-RPT-05 · avant transmission, une nouvelle génération remplace l\'instantané de la V1 ; sans classeur si le réseau le décide', function () {
    inSpace($this->manager)->postJson(generationUrl($this->report, '/generate'))->assertStatus(202);
    inSpace($this->manager)->patchJson(generationUrl($this->report), ['content' => ['trainings' => 'Trois sessions']])->assertOk()->assertJsonPath('data.status', 'draft');
    app(SettingsService::class)->set('report.legacy_workbook_enabled', false);

    inSpace($this->manager)->postJson(generationUrl($this->report, '/generate'))->assertStatus(202)->assertJsonPath('data.current_version', 1);

    $version = reportVersion($this->report);
    expect($version->snapshot['content']['trainings'])->toBe('Trois sessions')
        ->and($version->xlsx_document_id)->toBeNull()
        ->and($version->pdfDocument->version)->toBe(2)
        ->and(Tenancy::bypass(fn () => QuarterlyReportVersion::query()->count(), 'Test.'))->toBe(1);
});

it('prévisualise l\'objet de données sans rien enregistrer', function () {
    inSpace($this->manager)->getJson(generationUrl($this->report, '/preview'))->assertOk()
        ->assertJsonPath('data.report.label', 'T3 2026')
        ->assertJsonPath('data.cga.regional_directorate', 'DR Abidjan Nord')
        ->assertJsonPath('data.states.sheets.summary.totals.active_total', 3);

    expect(Tenancy::bypass(fn () => QuarterlyReportVersion::query()->count(), 'Test.'))->toBe(0);
});

it('générée avec reports.prepare ; un autre CGA répond 404', function () {
    $billing = Membership::factory()->role(Role::CgaBillingOfficer)->create(['organization_id' => $this->manager->organization_id]);
    $secretary = Membership::factory()->role(Role::CgaSecretary)->create(['organization_id' => $this->manager->organization_id]);

    inSpace($billing)->postJson(generationUrl($this->report, '/generate'))->assertForbidden();
    inSpace($billing)->getJson(generationUrl($this->report, '/versions'))->assertForbidden();
    inSpace(Membership::factory()->role(Role::CgaManager)->create())->postJson(generationUrl($this->report, '/generate'))->assertNotFound();
    inSpace($secretary)->postJson(generationUrl($this->report, '/generate'))->assertStatus(202);
});

it('RG-RPT-05 · un document sans version de rapport, ou une version sans instantané, ne se produit pas', function () {
    $version = QuarterlyReportVersion::factory()->create(['report_id' => $this->report->id, 'snapshot' => null]);
    $document = Tenancy::bypass(fn () => GeneratedDocument::factory()->create(['member_id' => null, 'type' => 'quarterly_report_docx', 'organization_id' => $this->manager->organization_id, 'source_type' => 'quarterly_report_version', 'source_id' => $version->id]), 'Test.');

    expect(fn () => ReportSnapshot::of($document))->toThrow(LogicException::class)
        ->and($version->report->is($this->report))->toBeTrue();
});
