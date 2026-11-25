<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Documents\Models\File;
use App\Domain\Imports\Actions\RunImport;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Imports\Importers\CgaRowImporter;
use App\Domain\Imports\Jobs\ProcessImport;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Files\StorageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/*
 * Import du registre des CGA (06-IMPLEMENTATION-PLAN étape 1.3, F-IMP-01, RG-ORG-01) : modèle Excel,
 * dépôt traité en file « imports », sort de chaque ligne (importée, à compléter, rejetée), rapport
 * d'anomalies ligne par ligne.
 */

const CGA_HEADERS = ['Dénomination *', 'Sigle', 'N° d\'agrément', 'Date d\'agrément', 'Ville *', 'Direction régionale', 'Téléphone *', 'E-mail'];

/**
 * Classeur Excel : une ligne d'en-tête puis les lignes données.
 *
 * @param  list<list<mixed>>  $rows
 * @param  list<string>  $headers
 */
function workbook(array $rows, array $headers = CGA_HEADERS): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'test-xlsx-');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($headers));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();
    $contents = (string) file_get_contents($path);
    unlink($path);

    return UploadedFile::fake()->createWithContent('registre.xlsx', $contents);
}

/**
 * Classeur Excel sans aucune ligne.
 */
function emptyWorkbook(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'test-xlsx-');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->close();
    $contents = (string) file_get_contents($path);
    unlink($path);

    return $contents;
}

/**
 * Lignes de la feuille d'un classeur.
 *
 * @return list<list<mixed>>
 */
function sheetRows(string $contents, int $sheetIndex = 0): array
{
    $path = (string) tempnam(sys_get_temp_dir(), 'test-xlsx-');
    file_put_contents($path, $contents);
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $index => $sheet) {
        if ($index === $sheetIndex + 1) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
    }
    $reader->close();
    unlink($path);

    return $rows;
}

/**
 * Dépose le fichier, puis traite l'import comme le ferait le worker de la file « imports ».
 */
function importCgas(Membership $membership, UploadedFile $file): ImportBatch
{
    Queue::fake();
    $id = (string) inSpace($membership)->post('/api/v1/network/imports/cgas', ['file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(202)
        ->json('data.id');

    Queue::assertPushedOn(ProcessImport::QUEUE, ProcessImport::class);
    $job = Queue::pushed(ProcessImport::class)->first();
    app()->call([$job, 'handle']);

    return app(TenantContext::class)->runAs($membership, fn (): ImportBatch => ImportBatch::query()->findOrFail($id));
}

beforeEach(function () {
    Storage::fake();
});

it('donne le modèle Excel du registre, avec une feuille d\'aide', function () {
    $response = inSpace(Membership::factory()->role(Role::CncgaManager)->create())->get('/api/v1/network/imports/cgas/template', ['Accept' => 'application/json'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertDownload('modele-import-registre-cga.xlsx');

    $contents = (string) $response->getContent();
    expect(sheetRows($contents)[0])->toBe(CGA_HEADERS)
        ->and(array_column(sheetRows($contents, 1), 1))->toBe(['Obligatoire', 'Oui', 'Non', 'Non', 'Non', 'Oui', 'Non', 'Oui', 'Non']);
});

it('réserve l\'import du registre à ceux qui gèrent le registre', function (Role $role) {
    $member = Membership::factory()->role($role)->create();

    inSpace($member)->getJson('/api/v1/network/imports/cgas/template')->assertForbidden();
    inSpace($member)->post('/api/v1/network/imports/cgas', ['file' => workbook([])], ['Accept' => 'application/json'])->assertForbidden();
    inSpace($member)->getJson('/api/v1/network/imports')->assertForbidden();
})->with([Role::CncgaViewer, Role::CncgaDues, Role::CgaManager]);

it('met le fichier en file « imports » et répond 202 avec le lot en attente', function () {
    Queue::fake();
    $president = Membership::factory()->role(Role::CncgaPresident)->create();

    inSpace($president)->post('/api/v1/network/imports/cgas', ['file' => workbook([['CGA Test', null, null, null, 'Abidjan', null, '0707070707', null]])], ['Accept' => 'application/json'])
        ->assertStatus(202)
        ->assertJsonPath('data.type', 'cgas')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.status_label', 'En attente')
        ->assertJsonPath('data.file_name', 'registre.xlsx')
        ->assertJsonPath('data.report', null)
        ->assertJsonPath('data.created_by.id', $president->user_id);

    Queue::assertPushedOn('imports', ProcessImport::class, fn (ProcessImport $job): bool => $job->membershipId === $president->id);
    $file = File::query()->sole();
    expect($file->organization_id)->toBe($president->organization_id)
        ->and($file->path)->toStartWith("orgs/{$president->organization_id}/imports/");
});

it('refuse un dépôt qui n\'est pas un classeur Excel', function () {
    $manager = Membership::factory()->role(Role::CncgaManager)->create();

    inSpace($manager)->post('/api/v1/network/imports/cgas', [], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    inSpace($manager)->post('/api/v1/network/imports/cgas', ['file' => UploadedFile::fake()->createWithContent('registre.csv', "nom;ville\nCGA;Abidjan\n")], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('RG-ORG-01 importe les 111 CGA du réseau depuis le modèle, non abonnés et provisoires', function () {
    $president = Membership::factory()->role(Role::CncgaPresident)->create();
    RegionalDirectorate::factory()->create(['code' => 'DR-ABJ-N', 'name' => 'DR Abidjan Nord']);
    $template = (string) inSpace($president)->get('/api/v1/network/imports/cgas/template', ['Accept' => 'application/json'])->getContent();
    $headers = sheetRows($template)[0];

    $rows = [];
    for ($i = 1; $i <= 111; $i++) {
        $number = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $rows[] = ["CGA du réseau {$number}", "CGA{$number}", "AGR-2026-{$number}", '15/03/2019', 'Abidjan', $i % 2 === 0 ? 'DR-ABJ-N' : 'dr abidjan nord', '07 07 07 07 07', "cga{$number}@reseau.ci"];
    }
    $batch = importCgas($president, workbook($rows, $headers));

    expect($batch->status)->toBe(ImportStatus::Done)
        ->and($batch->total_rows)->toBe(111)
        ->and($batch->imported_rows)->toBe(111)
        ->and($batch->draft_rows + $batch->rejected_rows)->toBe(0)
        ->and($batch->finished_at)->not->toBeNull();

    $imported = Organization::query()->where('name', 'like', 'CGA du réseau %')->get();
    expect($imported)->toHaveCount(111)
        ->and($imported->pluck('subscription_status')->unique()->all())->toBe([SubscriptionStatus::None])
        ->and($imported->pluck('agreement_status')->unique()->all())->toBe([AgreementStatus::Granted])
        ->and($imported->pluck('data_status')->unique()->all())->toBe([DataStatus::Provisional])
        ->and($imported->firstWhere('sigle', 'CGA007')?->phone_1)->toBe('+2250707070707')
        ->and($imported->firstWhere('sigle', 'CGA007')?->agreement_date?->toDateString())->toBe('2019-03-15')
        ->and($imported->whereNull('regional_directorate_id'))->toBeEmpty();
    expect(AuditLog::query()->where('event', 'created')->where('subject_id', $imported->first()?->id)->value('user_id'))->toBe($president->user_id);

    inSpace($president)->getJson("/api/v1/network/imports/{$batch->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'done')
        ->assertJsonPath('data.processed_rows', 111)
        ->assertJsonPath('data.report.name', fn (string $name): bool => str_starts_with($name, 'rapport-import-registre-cga-'));
});

it('RG-IMP-02 importe, importe à compléter ou rejette chaque ligne avec son motif', function () {
    $president = Membership::factory()->role(Role::CncgaPresident)->create();
    RegionalDirectorate::factory()->create(['code' => 'DR-BKE', 'name' => 'DR Bouaké']);
    Organization::factory()->create(['name' => 'CGA Éburnie', 'agreement_number' => 'AGR-PRIS']);

    $batch = importCgas($president, workbook([
        ['CGA Complet', 'CGAC', 'AGR-001', '01/02/2018', 'Bouaké', 'DR-BKE', '+2250707070707', 'contact@complet.ci'],
        ['CGA Sans date', null, 'AGR-002', null, 'Bouaké', 'DR-BKE', '0707070707', 'contact@sansdate.ci'],
        ['CGA DR inconnue', null, 'AGR-003', 43000, 'Korhogo', 'DR-XYZ', '0707070707', null],
        [null, null, null, null, null, null, null, null],
        [null, null, null, null, 'Man', null, '0707070707', null],
        ['CGA Téléphone faux', null, null, null, 'Man', null, '12', 'pas-un-email'],
        ['cga eburnie', null, null, null, 'Abidjan', null, '0707070707', null],
        ['CGA Doublon agrément', null, 'AGR-PRIS', '2020-01-01', 'Abidjan', null, '0707070707', null],
        ['CGA Futur', null, 'AGR-004', '01/01/2099', 'Abidjan', null, '0707070707', null],
        ['CGA Date illisible', null, 'AGR-005', '31/02/2020', 'Abidjan', null, '0707070707', null],
    ]));

    expect($batch->status)->toBe(ImportStatus::Done)
        ->and([$batch->total_rows, $batch->imported_rows, $batch->draft_rows, $batch->rejected_rows])->toBe([9, 1, 2, 6]);

    $rows = inSpace($president)->getJson("/api/v1/network/imports/{$batch->id}/rows")->assertOk()->json('data');
    $byRow = collect($rows)->keyBy('row_number');
    expect($byRow[2]['status'])->toBe('imported')
        ->and($byRow[3]['status'])->toBe('draft')
        ->and($byRow[3]['messages'])->toBe(['Agrément incomplet (numéro et date) : CGA créé avec l\'agrément « en cours ».'])
        ->and($byRow[4]['messages'])->toBe(['Direction régionale « DR-XYZ » inconnue du référentiel : à renseigner sur la fiche.', 'E-mail non renseigné(e).'])
        ->and($byRow->has(5))->toBeFalse()
        ->and($byRow[6]['messages'])->toBe(['Dénomination : champ obligatoire.'])
        ->and($byRow[7]['messages'])->toBe(['Téléphone invalide : « 12 ».', 'E-mail invalide : « pas-un-email ».'])
        ->and($byRow[8]['messages'])->toBe(['Doublon : un CGA du registre porte déjà cette dénomination.'])
        ->and($byRow[9]['messages'])->toBe(['Doublon : le numéro d\'agrément AGR-PRIS est déjà attribué à un autre CGA.'])
        ->and($byRow[10]['messages'])->toBe(['La date d\'agrément est dans le futur.'])
        ->and($byRow[11]['messages'])->toBe(['Date d\'agrément illisible : utilisez une cellule date ou le format jj/mm/aaaa.']);

    $pending = Organization::query()->where('name', 'CGA Sans date')->sole();
    expect($pending->agreement_status)->toBe(AgreementStatus::Pending)
        ->and(Organization::query()->where('name', 'CGA DR inconnue')->value('agreement_date'))->not->toBeNull()
        ->and($byRow[2]['created_entity_id'])->toBe(Organization::query()->where('name', 'CGA Complet')->value('id'));

    inSpace($president)->getJson("/api/v1/network/imports/{$batch->id}/rows?filter[status]=rejected")->assertJsonCount(6, 'data');
    inSpace($president)->getJson("/api/v1/network/imports/{$batch->id}/rows?filter[status]=inconnu")->assertUnprocessable();
});

it('écrit le rapport d\'anomalies ligne par ligne, avec sa synthèse', function () {
    $president = Membership::factory()->role(Role::CncgaPresident)->create();

    $batch = importCgas($president, workbook([
        ['CGA Rapport', null, null, null, 'Abidjan', null, '0707070707', 'contact@rapport.ci'],
        ['CGA Sans ville', null, null, null, null, null, '0707070707', null],
    ]));

    $report = app(StorageService::class)->contents(File::query()->findOrFail($batch->report_file_id));
    expect(sheetRows($report))->toBe([
        ['Ligne', 'Statut', 'Dénomination', 'Motifs ou champs à compléter'],
        [2, 'À compléter', 'CGA Rapport', 'Agrément incomplet (numéro et date) : CGA créé avec l\'agrément « en cours ». ; Direction régionale non renseigné(e).'],
        [3, 'Rejetée', 'CGA Sans ville', 'Ville : champ obligatoire.'],
    ])->and(sheetRows($report, 1))->toBe([['Lignes lues', 2], ['Importée', 0], ['À compléter', 1], ['Rejetée', 1]]);

    $url = (string) inSpace($president)->getJson("/api/v1/network/imports/{$batch->id}")->json('data.report.url');
    expect($url)->toContain('/api/v1/files/'.$batch->report_file_id.'/download')->toContain('signature=');
});

it('reconnaît les colonnes par leur en-tête, dans n\'importe quel ordre', function () {
    $president = Membership::factory()->role(Role::CncgaPresident)->create();

    $batch = importCgas($president, workbook(
        [['contact@ordre.ci', '0707070707', 'Yamoussoukro', 'CGA En désordre']],
        ['Email', 'Tél', 'VILLE', 'Nom du CGA'],
    ));

    expect($batch->draft_rows)->toBe(1)
        ->and(Organization::query()->where('name', 'CGA En désordre')->value('email'))->toBe('contact@ordre.ci');
});

it('met en échec un fichier dont l\'en-tête ne suit pas le modèle, motif au rapport', function () {
    $president = Membership::factory()->role(Role::CncgaPresident)->create();

    $batch = importCgas($president, workbook([['CGA', 'Abidjan']], ['Nom', 'Commentaire']));

    expect($batch->status)->toBe(ImportStatus::Failed)
        ->and($batch->rows()->sole()->messages)->toBe(['La ligne d\'en-tête ne suit pas le modèle : colonnes obligatoires absentes (Ville *, Téléphone *).'])
        ->and($batch->report_file_id)->not->toBeNull()
        ->and($batch->rows()->sole()->batch->is($batch))->toBeTrue();
    inSpace($president)->getJson("/api/v1/network/imports/{$batch->id}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.status_label', 'En échec');
});

it('met en échec un fichier illisible ou vide', function (string $case) {
    [$contents, $message] = match ($case) {
        'illisible' => ['pas un classeur', 'Le fichier ne peut pas être lu : déposez un classeur Excel (.xlsx) rempli d\'après le modèle.'],
        'vide' => [emptyWorkbook(), 'Le fichier est vide : la première feuille doit commencer par la ligne d\'en-tête du modèle.'],
    };
    $president = Membership::factory()->role(Role::CncgaPresident)->create();
    $batch = app(TenantContext::class)->runAs($president, function () use ($contents, $president): ImportBatch {
        $file = app(StorageService::class)->store($contents, 'registre.xlsx', 'imports', $president->organization_id, $president->user_id);
        $batch = new ImportBatch;
        $batch->type = ImportType::Cgas;
        $batch->file_id = $file->id;
        $batch->status = ImportStatus::Queued;
        $batch->created_by = $president->user_id;
        $batch->save();

        return app(RunImport::class)->execute($batch);
    });

    expect($batch->status)->toBe(ImportStatus::Failed)
        ->and($batch->rows()->sole()->messages)->toBe([$message]);
})->with(['illisible', 'vide']);

it('rejette une ligne en erreur technique sans arrêter l\'import', function () {
    $president = Membership::factory()->role(Role::CncgaPresident)->create();
    $this->partialMock(CgaRowImporter::class, fn ($mock) => $mock->shouldReceive('import')->andThrow(new RuntimeException('Panne de base')));

    $batch = importCgas($president, workbook([['CGA', null, null, null, 'Abidjan', null, '0707070707', null]]));

    expect($batch->status)->toBe(ImportStatus::Done)
        ->and($batch->rows()->sole()->messages)->toBe(['Ligne non importée à la suite d\'une erreur technique : réessayez, ou contactez l\'assistance.']);
});

it('met le lot en échec si le Job échoue', function () {
    $president = Membership::factory()->role(Role::CncgaPresident)->create();
    Queue::fake();
    $id = (string) inSpace($president)->post('/api/v1/network/imports/cgas', ['file' => workbook([])], ['Accept' => 'application/json'])->json('data.id');

    Queue::pushed(ProcessImport::class)->first()->failed(new RuntimeException('Worker arrêté'));

    $batch = app(TenantContext::class)->runAs($president, fn (): ImportBatch => ImportBatch::query()->findOrFail($id));
    expect($batch->status)->toBe(ImportStatus::Failed)->and($batch->finished_at)->not->toBeNull();
});

it('liste les imports de l\'organisation, les plus récents d\'abord, et cloisonne les lots', function () {
    $president = Membership::factory()->role(Role::CncgaPresident)->create();
    $first = importCgas($president, workbook([]));
    $this->travel(1)->minutes();
    $second = importCgas($president, workbook([]));

    inSpace($president)->getJson('/api/v1/network/imports')
        ->assertOk()
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('data.1.id', $first->id);
    inSpace($president)->getJson('/api/v1/network/imports?filter[status]=done&filter[type]=cgas')->assertJsonCount(2, 'data');
    inSpace($president)->getJson('/api/v1/network/imports?filter[type]=inconnu')->assertUnprocessable();

    $admin = Membership::factory()->role(Role::PlatformAdmin)->create();
    inSpace($admin)->getJson("/api/v1/network/imports/{$first->id}")->assertNotFound();
    inSpace($admin)->getJson('/api/v1/network/imports')->assertOk()->assertJsonCount(0, 'data');
    inSpace(Membership::factory()->role(Role::CncgaViewer)->create())->getJson("/api/v1/network/imports/{$first->id}")->assertForbidden();
    inSpace(Membership::factory()->role(Role::CncgaViewer)->create())->getJson("/api/v1/network/imports/{$first->id}/rows")->assertForbidden();
});

it('reprend l\'espace de celui qui a lancé le traitement, puis rétablit l\'espace d\'avant', function () {
    $context = app(TenantContext::class);
    $before = Membership::factory()->create();
    $runner = Membership::factory()->role(Role::CncgaManager)->create();
    $context->set($before);

    expect($context->runAs($runner, fn (): string => $context->organizationId()))->toBe($runner->organization_id)
        ->and($context->organizationId())->toBe($before->organization_id);
});
