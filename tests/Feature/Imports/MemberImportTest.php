<?php

use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Importers\MemberRowImporter;
use App\Domain\Imports\Jobs\ProcessImport;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Numbering\SequenceService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\RealTransactions;

/*
 * Import d'un portefeuille existant (06-IMPLEMENTATION-PLAN étape 2.6, F-IMP-02, RG-IMP-01 à 03) :
 * modèle, analyse et aperçu, import en file « imports », double numérotation, rapport. La durée de
 * l'import de 1 000 lignes (groupe « performance ») se mesure seule : composer test:performance.
 */

beforeEach(function () {
    Storage::fake();
    TaxOffice::factory()->create(['code' => 'CDI-TST', 'name' => 'CDI de test']);
    Sector::factory()->create(['code' => 'TST001', 'name' => 'Commerce de test']);
});

function portfolioManager(?string $abbreviation = 'DCK'): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create([
        'organization_id' => Organization::factory()->create(['numbering_abbreviation' => $abbreviation, 'abbreviation_locked_at' => null])->id,
    ]);
}

/**
 * Ligne complète d'un adhérent actif du portefeuille CGA, par clé de colonne du modèle.
 *
 * @return array<string, mixed>
 */
function portfolioRow(array $overrides = []): array
{
    return [
        'portfolio' => 'CGA', 'legacy_number' => 'ADH-145', 'status' => 'Actif', 'adhesion_date' => '10/05/2024', 'company_name' => 'Ets Koné et fils',
        'legal_form' => 'SARL', 'ncc' => fake()->unique()->numerify('#######').'K', 'regime' => 'TEE', 'main_activity' => 'Commerce de détail',
        'sector' => 'TST001', 'tax_office' => 'CDI de test', 'address' => 'Cocody, Riviera 3', 'phone' => '0707070707',
        'signatory_name' => 'Awa Koné', 'signatory_title' => 'Gérante', 'fees_amount' => 20000, 'fees_periodicity' => 'Mensuelle',
        ...$overrides,
    ];
}

/**
 * Classeur du modèle : la ligne d'en-tête puis les lignes données par clé de colonne.
 *
 * @param  list<array<string, mixed>>  $rows
 * @param  list<string>|null  $keys  colonnes du fichier (toutes par défaut)
 */
function portfolioWorkbook(array $rows, ?array $keys = null): UploadedFile
{
    $columns = app(MemberRowImporter::class)->columns();
    $keys ??= array_keys($columns);
    $path = (string) tempnam(sys_get_temp_dir(), 'test-xlsx-');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(array_map(static fn (string $key): string => $columns[$key]['label'], $keys)));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues(array_map(static fn (string $key): mixed => $row[$key] ?? null, $keys)));
    }
    $writer->close();
    $contents = (string) file_get_contents($path);
    unlink($path);

    return UploadedFile::fake()->createWithContent('portefeuille.xlsx', $contents);
}

function previewPortfolio(Membership $manager, UploadedFile $file, ?int $start = null): TestResponse
{
    return inSpace($manager)->post('/api/v1/cga/imports/members/preview', array_filter(['file' => $file, 'start_number' => $start]), ['Accept' => 'application/json']);
}

/**
 * Dépose le portefeuille, puis le traite comme le ferait le worker de la file « imports ».
 */
function importPortfolio(Membership $manager, UploadedFile $file, ?int $start = null): ImportBatch
{
    Queue::fake();
    $id = (string) inSpace($manager)->post('/api/v1/cga/imports/members', array_filter(['file' => $file, 'start_number' => $start]), ['Accept' => 'application/json'])
        ->assertStatus(202)
        ->json('data.id');

    Queue::assertPushedOn(ProcessImport::QUEUE, ProcessImport::class, fn (ProcessImport $job): bool => $job->options === ['start_number' => $start ?? 1]);
    app()->call([Queue::pushed(ProcessImport::class)->first(), 'handle']);

    return app(TenantContext::class)->runAs($manager, fn (): ImportBatch => ImportBatch::query()->findOrFail($id));
}

/**
 * Portefeuille d'exemple : trois dossiers actifs dans le désordre des dates, deux brouillons, quatre lignes rejetées.
 *
 * @return list<array<string, mixed>>
 */
function samplePortfolio(string $ncc): array
{
    $first = portfolioRow(['ncc' => $ncc]);

    return [
        $first,
        portfolioRow(['legacy_number' => '12', 'adhesion_date' => '15/01/2023', 'company_name' => 'Boulangerie Yao']),
        portfolioRow(['portfolio' => 'Cabinet', 'legacy_number' => 'C-9', 'regime' => 'RSI', 'adhesion_date' => '01/02/2025', 'company_name' => 'Cabinet client']),
        portfolioRow(['legacy_number' => '77', 'sector' => null, 'tax_office' => null, 'address' => null, 'company_name' => 'Fiche incomplète']),
        portfolioRow(['legacy_number' => '78', 'status' => 'Brouillon', 'company_name' => 'Adhésion à valider']),
        portfolioRow(['ncc' => '12AB', 'company_name' => 'NCC faux']),
        portfolioRow(['ncc' => $first['ncc'], 'company_name' => 'Doublon du fichier']),
        portfolioRow(['portfolio' => 'Cabinet', 'referrer' => 'Accessoire 1', 'company_name' => 'Cabinet avec apporteur']),
        portfolioRow(['regime' => 'RSI', 'company_name' => 'Réel au CGA']),
    ];
}

it('fournit le modèle du portefeuille, colonne par colonne', function () {
    $response = inSpace(portfolioManager())->get('/api/v1/cga/imports/members/template')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $path = (string) tempnam(sys_get_temp_dir(), 'test-xlsx-');
    file_put_contents($path, (string) $response->getContent());
    $reader = new Reader;
    $reader->open($path);
    $header = null;
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $header ??= $row->toArray();
        }
    }
    $reader->close();
    unset($reader, $sheet, $row);
    gc_collect_cycles();
    unlink($path);

    expect($header)->toContain('Portefeuille *', 'Ancien n°', 'Date d\'adhésion', 'Apporteur', 'NCC *', 'Honoraires (FCFA)')
        ->and($response->headers->get('Content-Disposition'))->toContain('modele-import-portefeuille.xlsx');
});

it('RG-IMP-01 analyse sans rien écrire : sort de chaque ligne et ancien n° en regard du n° CGA Connect, par date d\'adhésion', function () {
    $manager = portfolioManager();

    $data = previewPortfolio($manager, portfolioWorkbook(samplePortfolio('1450145K')))->assertOk()->json('data');

    expect($data)->toMatchArray(['total' => 9, 'importable' => 3, 'drafts' => 2, 'rejected' => 4, 'proposed_start_number' => 1, 'start_number' => 1]);
    $rows = collect($data['rows'])->keyBy('row');
    expect($rows[3]['number'])->toBe('2023-DCK-0001')
        ->and($rows[2]['number'])->toBe('2024-DCK-0002')
        ->and($rows[2]['legacy_number'])->toBe('ADH-145')
        ->and($rows[4]['number'])->toBe('CAB-DCK-00001')
        ->and($rows[5]['status'])->toBe('draft')
        ->and($rows[5]['messages'])->toBe(['CDI non renseigné(e).', 'Secteur non renseigné(e).', 'Adresse non renseigné(e).'])
        ->and($rows[6]['messages'][0])->toBe('Statut « Brouillon » dans le fichier : adhésion à valider.')
        ->and($rows[7]['messages'])->toBe(['NCC mal formé : « 12AB » (7 chiffres et une lettre).'])
        ->and($rows[8]['messages'])->toBe(['Doublon : le NCC 1450145K figure déjà à la ligne 2 du fichier.'])
        ->and($rows[9]['messages'])->toBe(['Un apporteur n\'est rattaché qu\'à un dossier du portefeuille CGA.'])
        ->and($rows[10]['messages'])->toBe(['Régime RSI : le portefeuille CGA n\'accueille que le RME et le TEE.'])
        ->and(Member::query()->withoutGlobalScopes()->count())->toBe(0);

    $started = previewPortfolio($manager, portfolioWorkbook(samplePortfolio('1450145K')), 150)->assertOk()->json('data.rows');
    expect(collect($started)->keyBy('row')[3]['number'])->toBe('2023-DCK-0150');
});

it('RG-IMP-01 importe le portefeuille : ancien n° gardé, n° CGA Connect chronologique depuis le numéro de départ, rapport', function () {
    $manager = portfolioManager();

    $batch = importPortfolio($manager, portfolioWorkbook(samplePortfolio('1450145K')), 150);

    expect($batch->status)->toBe(ImportStatus::Done)
        ->and([$batch->total_rows, $batch->imported_rows, $batch->draft_rows, $batch->rejected_rows])->toBe([9, 3, 2, 4])
        ->and($batch->report_file_id)->not->toBeNull();

    $members = app(TenantContext::class)->runAs($manager, fn () => Member::query()->get()->keyBy('legacy_number'));
    expect($members)->toHaveCount(5)
        ->and($members['12']->membership_number)->toBe('2023-DCK-0150')
        ->and($members['ADH-145']->membership_number)->toBe('2024-DCK-0151')
        ->and($members['ADH-145']->status)->toBe(MemberStatus::Active)
        ->and($members['ADH-145']->adhesion_date?->toDateString())->toBe('2024-05-10')
        ->and($members['ADH-145']->cga_contract_signed_on?->toDateString())->toBe('2024-05-10')
        ->and($members['ADH-145']->cga_contract_confirmed_by)->toBe($manager->user_id)
        ->and($members['ADH-145']->pieces_status->value)->toBe('pending')
        ->and($members['ADH-145']->fees_amount)->toBe(20_000)
        ->and($members['C-9']->client_number)->toBe('CAB-DCK-00001')
        ->and($members['C-9']->membership_number)->toBeNull()
        ->and($members['77']->status)->toBe(MemberStatus::Draft)
        ->and($members['77']->membership_number)->toBeNull()
        ->and(app(SequenceService::class)->current($manager->organization_id, 'membership'))->toBe(151)
        ->and(Organization::query()->findOrFail($manager->organization_id)->abbreviation_locked_at)->not->toBeNull();

    inSpace($manager)->getJson("/api/v1/cga/imports/{$batch->id}/rows?filter[status]=imported")
        ->assertOk()
        ->assertJsonPath('data.0.messages', ['N° 2024-DCK-0151 (ancien n° ADH-145).']);
    inSpace($manager)->getJson('/api/v1/cga/members?filter[search]=ADH-145')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.legacy_number', 'ADH-145');
    inSpace($manager)->getJson('/api/v1/cga/imports')->assertOk()->assertJsonPath('data.0.id', $batch->id);
});

it('RG-IMP-02 rattache apporteur, secteur, CDI et commune par leur code ou leur nom ; un inconnu laisse un brouillon', function () {
    $manager = portfolioManager();
    $referrer = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id, 'label' => 'Accessoire 1']);
    $commune = Commune::factory()->create(['name' => 'Cocody']);

    importPortfolio($manager, portfolioWorkbook([
        portfolioRow(['referrer' => 'accessoire 1', 'commune' => 'COCODY', 'sector' => 'commerce de test', 'tax_office' => 'cdi-tst']),
        portfolioRow(['legacy_number' => '9', 'sector' => 'Agriculture', 'referrer' => 'Accessoire 9']),
        portfolioRow(['legacy_number' => '10', 'legal_form' => 'EI', 'owner_last_name' => 'Yao']),
    ]));

    $members = app(TenantContext::class)->runAs($manager, fn () => Member::query()->get()->keyBy('legacy_number'));
    expect($members['ADH-145']->referrer_id)->toBe($referrer->id)
        ->and($members['ADH-145']->commune_id)->toBe($commune->id)
        ->and($members['ADH-145']->status)->toBe(MemberStatus::Active)
        ->and($members['9']->status)->toBe(MemberStatus::Draft)
        ->and($members['10']->entity_type->value)->toBe('individual')
        ->and($members['10']->status)->toBe(MemberStatus::Draft);
    $rows = app(TenantContext::class)->runAs($manager, fn () => ImportBatch::query()->sole()->rows()->orderBy('row_number')->pluck('messages')->all());
    expect($rows[1])->toBe(['Secteur « Agriculture » inconnu(e) du référentiel : à renseigner sur la fiche.', 'Apporteur « Accessoire 9 » inconnu(e) du référentiel : à renseigner sur la fiche.'])
        ->and($rows[2])->toContain('Prénoms du dirigeant non renseigné(e).');
});

it('rejette un NCC déjà ouvert dans le centre ou inscrit au portefeuille CGA d\'un autre CGA, et une ligne sans champ obligatoire', function () {
    $manager = portfolioManager();
    $open = Member::factory()->create(['organization_id' => $manager->organization_id]);
    $elsewhere = Member::factory()->active()->create();

    $data = previewPortfolio($manager, portfolioWorkbook([
        portfolioRow(['ncc' => $open->ncc]),
        portfolioRow(['ncc' => $elsewhere->ncc]),
        portfolioRow(['ncc' => $elsewhere->ncc, 'portfolio' => 'Cabinet']),
        portfolioRow(['company_name' => null, 'phone' => 'pas un numéro', 'fees_amount' => 'vingt mille', 'adhesion_date' => '31/02/2024', 'status' => 'Suspendu']),
    ]))->assertOk()->json('data');

    expect(array_column($data['rows'], 'status'))->toBe(['rejected', 'rejected', 'imported', 'rejected'])
        ->and($data['rows'][0]['messages'])->toBe(["Doublon : le NCC {$open->ncc} a déjà un dossier ouvert dans le centre."])
        ->and($data['rows'][1]['messages'])->toBe([__('errors.membership_exclusive')])
        ->and($data['rows'][3]['messages'])->toBe([
            'Raison sociale : champ obligatoire.',
            'Téléphone : valeur « pas un numéro » non reconnue.',
            'Honoraires (FCFA) : valeur « vingt mille » non reconnue.',
            'Statut : valeur « Suspendu » non reconnue.',
            'Date d\'adhésion illisible : utilisez une cellule date ou le format jj/mm/aaaa.',
        ]);
});

it('refuse un numéro de départ déjà attribué, un centre sans abréviation, un fichier illisible et un rôle sans le droit', function () {
    $manager = portfolioManager();
    importPortfolio($manager, portfolioWorkbook([portfolioRow()]));

    previewPortfolio($manager, portfolioWorkbook([portfolioRow()]), 1)->assertUnprocessable()
        ->assertJsonPath('errors.start_number.0', 'Ce numéro est déjà attribué : la séquence reprend au plus tôt à 2.');
    inSpace($manager)->post('/api/v1/cga/imports/members', ['file' => portfolioWorkbook([portfolioRow()]), 'start_number' => 1], ['Accept' => 'application/json'])->assertUnprocessable();
    previewPortfolio(portfolioManager(null), portfolioWorkbook([portfolioRow()]))->assertStatus(409)->assertJsonPath('code', 'abbreviation_missing');
    previewPortfolio($manager, portfolioWorkbook([portfolioRow()], ['company_name', 'ncc']))->assertUnprocessable()->assertJsonValidationErrors('file');
    previewPortfolio($manager, UploadedFile::fake()->createWithContent('note.txt', 'texte'))->assertUnprocessable()->assertJsonValidationErrors('file');

    $expert = Membership::factory()->role(Role::CgaAccountantExpert)->create(['organization_id' => $manager->organization_id]);
    inSpace($expert)->get('/api/v1/cga/imports/members/template')->assertForbidden();
    previewPortfolio($expert, portfolioWorkbook([portfolioRow()]))->assertForbidden();
    inSpace($expert)->getJson('/api/v1/cga/imports')->assertForbidden();
    inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->post('/api/v1/cga/imports/members', ['file' => portfolioWorkbook([portfolioRow()])], ['Accept' => 'application/json'])->assertForbidden();
});

it('importe 1 000 lignes en moins de 2 minutes, chaque ligne gardant son ancien numéro', function () {
    $manager = portfolioManager();
    $rows = array_map(static fn (int $n): array => portfolioRow([
        'legacy_number' => "ANC-{$n}",
        'ncc' => sprintf('%07dZ', $n),
        'company_name' => "Entreprise {$n}",
        'adhesion_date' => CarbonImmutable::create(2020, 1, 1)->addDays(1000 - $n)->format('d/m/Y'),
    ]), range(1, 1000));
    $file = portfolioWorkbook($rows);

    // Comme le worker : chaque ligne dans sa propre transaction, hors de celle du test.
    [$batch, $seconds, $legacy] = RealTransactions::run(function () use ($manager, $file): array {
        $started = microtime(true);
        $batch = importPortfolio($manager, $file);
        $seconds = microtime(true) - $started;

        return [$batch, $seconds, app(TenantContext::class)->runAs($manager, fn () => Member::query()->whereNotNull('membership_number')->orderBy('membership_seq')->pluck('legacy_number')->all())];
    });

    expect($batch->imported_rows)->toBe(1000)
        ->and($seconds)->toBeLessThan(120)
        ->and($legacy)->toHaveCount(1000)
        ->and($legacy[0])->toBe('ANC-1000')
        ->and($legacy[999])->toBe('ANC-1');
})->group('performance');
