<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Documents\Models\File;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Referentials\Enums\SectorStatus;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\NationalReference;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Files\StorageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
 * Données de référence provisoires (06-IMPLEMENTATION-PLAN étape 1.10, RG-DATA-01, 02, 05) : jeux
 * remplaçables par fichier (« mettre à jour » ou « remplacer », aperçu avant confirmation),
 * « Marquer comme validé », fusion d'une valeur utilisée.
 */

/**
 * @param  list<string>  $headers
 * @param  list<list<mixed>>  $rows
 */
function referenceFile(array $headers, array $rows): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'test-ref-');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($headers));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();
    $contents = (string) file_get_contents($path);
    unlink($path);

    return UploadedFile::fake()->createWithContent('referentiel.xlsx', $contents);
}

/**
 * Aperçu puis confirmation d'un fichier.
 *
 * @return array<string, mixed> effet appliqué
 */
function replaceWith(Membership $actor, string $dataset, UploadedFile $file, string $mode = 'replace'): array
{
    $fileId = inSpace($actor)->post("/api/v1/reference-data/{$dataset}/preview", ['file' => $file, 'mode' => $mode], ['Accept' => 'application/json'])
        ->assertOk()->json('data.file_id');

    return inSpace($actor)->postJson("/api/v1/reference-data/{$dataset}/apply", ['file_id' => $fileId, 'mode' => $mode])->assertOk()->json('data');
}

function referentialsManager(): Membership
{
    return Membership::factory()->role(Role::CncgaManager)->create();
}

beforeEach(function () {
    Storage::fake();
});

it('RG-DATA-02 montre chaque jeu avec son nombre d\'éléments et de provisoires', function () {
    RegionalDirectorate::factory()->count(2)->create();
    RegionalDirectorate::factory()->create(['data_status' => DataStatus::Validated]);

    $response = inSpace(referentialsManager())->getJson('/api/v1/reference-data')->assertOk();
    $directorates = collect($response->json('data'))->firstWhere('dataset', 'regional-directorates');

    expect($directorates)->toMatchArray(['label' => 'Directions régionales', 'count' => 3, 'provisional_count' => 2, 'status' => 'provisional', 'can' => ['manage' => true]])
        ->and(array_column($response->json('data'), 'dataset'))->toBe(['cgas', 'regional-directorates', 'tax-offices', 'communes', 'national-references'])
        ->and($response->json('meta.provisional_count'))->toBeGreaterThanOrEqual(2);
    inSpace(Membership::factory()->role(Role::CgaManager)->create())->getJson('/api/v1/reference-data')->assertForbidden();
});

it('donne le modèle Excel de chaque jeu', function (string $dataset, string $name) {
    inSpace(referentialsManager())->get("/api/v1/reference-data/{$dataset}/template", ['Accept' => 'application/json'])
        ->assertOk()
        ->assertDownload("modele-{$name}.xlsx");
})->with([
    ['cgas', 'registre-cga'], ['regional-directorates', 'directions-regionales'], ['tax-offices', 'centres-des-impots'],
    ['communes', 'communes'], ['national-references', 'references-nationales'],
]);

it('RG-DATA-01 montre l\'effet d\'un fichier sans rien écrire, ligne par ligne', function () {
    $actor = referentialsManager();
    RegionalDirectorate::factory()->create(['code' => 'DR-A', 'name' => 'Ancien nom']);
    RegionalDirectorate::factory()->create(['code' => 'DR-B', 'name' => 'Inchangée']);
    RegionalDirectorate::factory()->create(['code' => 'DR-Z', 'name' => 'Absente du fichier']);

    $preview = inSpace($actor)->post('/api/v1/reference-data/regional-directorates/preview', ['mode' => 'replace', 'file' => referenceFile(['Code *', 'Nom *', 'Zone de la carte', 'Active'], [
        ['dr-a', 'Nouveau nom', 'abidjan', 'Oui'],
        ['DR-B', 'Inchangée', null, null],
        ['DR-C', 'Nouvelle', null, 'non'],
        ['DR C', 'Code invalide', null, null],
        ['DR-C', 'Doublon', null, null],
        ['DR-D', 'Active illisible', null, 'peut-être'],
    ])], ['Accept' => 'application/json'])->assertOk()->json('data');

    expect($preview['summary'])->toBe(['creates' => 1, 'updates' => 1, 'unchanged' => 1, 'deletes' => 1, 'deactivates' => 0, 'kept' => 0, 'rejected' => 3])
        ->and($preview['updates'][0]['changes'])->toBe(['name' => ['before' => 'Ancien nom', 'after' => 'Nouveau nom'], 'geo_key' => ['before' => null, 'after' => 'abidjan']])
        ->and($preview['removals'][0])->toMatchArray(['label' => 'DR-Z — Absente du fichier', 'action' => 'delete', 'action_label' => 'Supprimée'])
        ->and(array_column($preview['rejected'], 'messages'))->toBe([
            ['Code invalide : lettres, chiffres, tiret ou souligné seulement.'],
            ['Doublon dans le fichier : même valeur qu\'à la ligne 4.'],
            ['Active : écrire Oui ou Non.'],
        ])
        ->and(RegionalDirectorate::query()->where('code', 'DR-A')->value('name'))->toBe('Ancien nom')
        ->and(File::query()->whereKey($preview['file_id'])->value('organization_id'))->toBe($actor->organization_id);
});

it('RG-DATA-05 remplace les DR : une DR absente et utilisée est désactivée, jamais supprimée', function () {
    $actor = referentialsManager();
    $used = RegionalDirectorate::factory()->create(['code' => 'DR-USED']);
    TaxOffice::factory()->create(['regional_directorate_id' => $used->id]);
    RegionalDirectorate::factory()->create(['code' => 'DR-FREE']);

    $result = replaceWith($actor, 'regional-directorates', referenceFile(['Code *', 'Nom *'], [['DR-NEW', 'Nouvelle DR']]));

    expect($result['summary'])->toMatchArray(['creates' => 1, 'deletes' => 1, 'deactivates' => 1])
        ->and($used->refresh()->active)->toBeFalse()
        ->and(RegionalDirectorate::query()->where('code', 'DR-FREE')->exists())->toBeFalse()
        ->and(RegionalDirectorate::query()->where('code', 'DR-NEW')->sole()->data_status)->toBe(DataStatus::Provisional)
        ->and(AuditLog::query()->where('event', 'created')->where('subject_type', (new RegionalDirectorate)->getMorphClass())->exists())->toBeTrue();
});

it('met à jour les CDI, rattachés à une DR existante', function () {
    $directorate = RegionalDirectorate::factory()->create(['code' => 'DRAN-4']);
    $office = TaxOffice::factory()->create(['code' => 'CDI-OLD']);

    $result = replaceWith(referentialsManager(), 'tax-offices', referenceFile(['Code *', 'Nom *', 'Code de la DR *'], [
        ['CDI-2PLAT', 'II Plateaux 2', 'dran-4'],
        ['CDI-X', 'DR inconnue', 'DR-INCONNUE'],
    ]), 'update');

    expect($result['summary'])->toMatchArray(['creates' => 1, 'rejected' => 1, 'deletes' => 0])
        ->and($result['rejected'][0]['messages'])->toBe(['Direction régionale « DR-INCONNUE » inconnue du référentiel : importez d\'abord les DR.'])
        ->and(TaxOffice::query()->where('code', 'CDI-2PLAT')->sole()->regional_directorate_id)->toBe($directorate->id)
        ->and($office->refresh()->exists)->toBeTrue();
});

it('remplace les communes : une commune utilisée est gardée, une DR inconnue laissée vide', function () {
    $used = Commune::factory()->create(['name' => 'Ancienne utilisée']);
    Organization::factory()->create(['commune_id' => $used->id]);
    Commune::factory()->create(['name' => 'Ancienne libre']);

    $result = replaceWith(referentialsManager(), 'communes', referenceFile(['Nom *', 'Code de la DR'], [['Cocody', 'DR-INCONNUE'], ['COCODY', null]]));

    expect($result['summary'])->toMatchArray(['creates' => 1, 'kept' => 1, 'deletes' => 1, 'rejected' => 1])
        ->and($result['warnings'][0]['messages'])->toBe(['Direction régionale « DR-INCONNUE » inconnue du référentiel : laissée vide.'])
        ->and(Commune::query()->pluck('name')->sort()->values()->all())->toBe(['Ancienne utilisée', 'Cocody']);
});

it('met à jour les références nationales par année', function () {
    NationalReference::factory()->create(['year' => 2025, 'nominal_gdp' => 1]);

    $result = replaceWith(referentialsManager(), 'national-references', referenceFile(['Année *', 'Recettes fiscales intérieures', 'PIB nominal', 'Source du PIB'], [
        [2025, '5 000 000 000 000', 50000000000000, 'INS'],
        [2026, null, 'beaucoup', null],
        ['26', null, null, null],
    ]), 'update');

    expect($result['summary'])->toMatchArray(['updates' => 1, 'rejected' => 2])
        ->and(NationalReference::query()->where('year', 2025)->sole()->only(['domestic_tax_revenue', 'nominal_gdp', 'gdp_source']))
        ->toBe(['domestic_tax_revenue' => 5_000_000_000_000, 'nominal_gdp' => 50_000_000_000_000, 'gdp_source' => 'INS']);
});

it('RG-ORG-01 remplace la liste provisoire des CGA par le fichier réel sans casser aucune donnée liée', function () {
    $actor = Membership::factory()->role(Role::CncgaPresident)->create();
    $withUsers = Organization::factory()->create(['name' => 'CGA Éburnie Conseil', 'agreement_number' => null]);
    $agent = Membership::factory()->create(['organization_id' => $withUsers->id]);
    $renamed = Organization::factory()->create(['name' => 'Ancien nom', 'agreement_number' => '301/SEPMBPE/DGI', 'agreement_status' => AgreementStatus::Granted]);
    $orphan = Organization::factory()->create(['name' => 'CGA Fantôme']);
    $absentWithData = Organization::factory()->create(['name' => 'CGA Absent Actif']);
    Membership::factory()->create(['organization_id' => $absentWithData->id]);

    $result = replaceWith($actor, 'cgas', referenceFile(['Dénomination *', 'N° d\'agrément', 'Date d\'agrément', 'Ville *', 'Téléphone *'], [
        ['CGA EBURNIE CONSEIL', '401/SEPMBPE/DGI', '15/03/2019', 'Abidjan', null],
        ['CGA EXCELLENCE', '301/SEPMBPE/DGI', null, 'Abidjan – Plateau', '0707070707'],
        ['CGA Nouveau', null, null, 'Man', '0707070707'],
        ['CGA Sans téléphone', null, null, 'Man', null],
        ['CGA Conflit', '301/SEPMBPE/DGI', null, 'Man', '0707070707'],
    ]));

    expect($result['summary'])->toMatchArray(['creates' => 1, 'updates' => 2, 'deletes' => 1, 'kept' => 1, 'rejected' => 2])
        ->and($withUsers->refresh()->agreement_number)->toBe('401/SEPMBPE/DGI')
        ->and($withUsers->agreement_status)->toBe(AgreementStatus::Granted)
        ->and($withUsers->name)->toBe('CGA EBURNIE CONSEIL')
        ->and(Membership::query()->whereKey($agent->id)->usable()->exists())->toBeTrue()
        ->and($renamed->refresh()->name)->toBe('CGA EXCELLENCE')
        ->and(Organization::query()->find($orphan->id))->toBeNull()
        ->and(Organization::withTrashed()->find($orphan->id))->not->toBeNull()
        ->and($absentWithData->refresh()->exists)->toBeTrue()
        ->and(array_column($result['rejected'], 'messages'))->toBe([
            ['Téléphone obligatoire pour un nouveau CGA.'],
            ['Doublon dans le fichier : même valeur qu\'à la ligne 3.'],
        ]);
});

it('refuse une ligne dont le numéro d\'agrément et la dénomination désignent deux CGA différents', function () {
    Organization::factory()->create(['name' => 'CGA Titulaire', 'agreement_number' => 'AGR-PRIS']);
    Organization::factory()->create(['name' => 'CGA Autre', 'agreement_number' => null]);

    $result = replaceWith(referentialsManager(), 'cgas', referenceFile(['Dénomination *', 'N° d\'agrément', 'Ville *'], [['CGA Autre', 'agr-pris', 'Abidjan']]), 'update');

    expect($result['summary'])->toMatchArray(['updates' => 0, 'rejected' => 1])
        ->and($result['rejected'][0]['messages'])->toBe(['Doublon : un CGA du registre porte déjà cette dénomination.'])
        ->and(Organization::query()->where('agreement_number', 'AGR-PRIS')->value('name'))->toBe('CGA Titulaire');
});

it('refuse un fichier illisible, une confirmation sur un fichier d\'ailleurs, et les droits manquants', function () {
    $actor = referentialsManager();

    inSpace($actor)->post('/api/v1/reference-data/communes/preview', ['mode' => 'update', 'file' => referenceFile(['Colonne'], [['x']])], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('errors.file.0', 'La ligne d\'en-tête ne suit pas le modèle : colonnes obligatoires absentes (Nom *).');
    inSpace($actor)->post('/api/v1/reference-data/communes/preview', ['mode' => 'fusionner', 'file' => referenceFile(['Nom *'], [])], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('mode');
    inSpace($actor)->postJson('/api/v1/reference-data/communes/apply', ['file_id' => strtolower((string) Str::ulid()), 'mode' => 'update'])->assertNotFound();

    $foreign = app(TenantContext::class)->runAs($other = Membership::factory()->role(Role::PlatformAdmin)->create(), fn () => app(StorageService::class)->store('x', 'x.xlsx', 'reference_data', $other->organization_id, $other->user_id));
    inSpace($actor)->postJson('/api/v1/reference-data/communes/apply', ['file_id' => $foreign->id, 'mode' => 'update'])->assertNotFound();

    $viewer = Membership::factory()->role(Role::CncgaViewer)->create();
    inSpace($viewer)->post('/api/v1/reference-data/cgas/preview', ['mode' => 'update', 'file' => referenceFile(['Dénomination *'], [])], ['Accept' => 'application/json'])->assertForbidden();
    inSpace(Membership::factory()->role(Role::CgaManager)->create())->postJson('/api/v1/reference-data/communes/validate')->assertForbidden();
    inSpace($actor)->getJson('/api/v1/reference-data/inconnu/template')->assertNotFound();
});

it('RG-DATA-02 marque un jeu comme validé', function () {
    RegionalDirectorate::factory()->count(2)->create();

    inSpace(referentialsManager())->postJson('/api/v1/reference-data/regional-directorates/validate')
        ->assertOk()->assertJsonPath('data.validated', 2);

    expect(RegionalDirectorate::query()->where('data_status', DataStatus::Provisional)->count())->toBe(0)
        ->and(RegionalDirectorate::query()->whereNull('validated_at')->count())->toBe(0);
    inSpace(referentialsManager())->postJson('/api/v1/reference-data/regional-directorates/validate')->assertJsonPath('data.validated', 0);
});

describe('fusion d\'une valeur (RG-DATA-05)', function () {
    it('reprend toutes les données de la DR fusionnée, puis la supprime', function () {
        [$source, $target] = RegionalDirectorate::factory()->count(2)->create();
        $office = TaxOffice::factory()->create(['regional_directorate_id' => $source->id]);
        $cga = Organization::factory()->create(['regional_directorate_id' => $source->id]);

        inSpace(referentialsManager())->postJson("/api/v1/referentials/regional-directorates/{$source->id}/merge", ['into_id' => $target->id])
            ->assertOk()->assertJsonPath('data.id', $target->id);

        expect($office->refresh()->regional_directorate_id)->toBe($target->id)
            ->and($cga->refresh()->regional_directorate_id)->toBe($target->id)
            ->and(RegionalDirectorate::query()->find($source->id))->toBeNull()
            ->and(AuditLog::query()->where('event', 'merged')->where('subject_id', $source->id)->value('context'))->toBe(['into' => $target->id]);
    });

    it('fusionne un CDI dans un CDI de la même DR seulement, et une commune', function () {
        $office = TaxOffice::factory()->create();
        $sibling = TaxOffice::factory()->create(['regional_directorate_id' => $office->regional_directorate_id]);
        $elsewhere = TaxOffice::factory()->create();
        [$commune, $otherCommune] = Commune::factory()->count(2)->create();
        $actor = referentialsManager();

        inSpace($actor)->postJson("/api/v1/referentials/tax-offices/{$office->id}/merge", ['into_id' => $elsewhere->id])
            ->assertUnprocessable()->assertJsonPath('code', 'merge_other_directorate');
        inSpace($actor)->postJson("/api/v1/referentials/tax-offices/{$office->id}/merge", ['into_id' => $sibling->id])->assertOk();
        inSpace($actor)->postJson("/api/v1/referentials/communes/{$commune->id}/merge", ['into_id' => $otherCommune->id])->assertOk();
        inSpace($actor)->postJson("/api/v1/referentials/communes/{$otherCommune->id}/merge", ['into_id' => $otherCommune->id])
            ->assertUnprocessable()->assertJsonPath('code', 'merge_same_value');
    });

    it('marque un secteur fusionné vers sa cible, validée', function () {
        $sector = Sector::factory()->create();
        $target = Sector::factory()->create(['status' => SectorStatus::Validated]);
        $rejected = Sector::factory()->create(['status' => SectorStatus::Rejected]);
        $actor = Membership::factory()->role(Role::PlatformAdmin)->create();

        inSpace($actor)->postJson("/api/v1/referentials/sectors/{$sector->id}/merge", ['into_id' => $rejected->id])
            ->assertUnprocessable()->assertJsonPath('code', 'merge_target_inactive');
        inSpace($actor)->postJson("/api/v1/referentials/sectors/{$sector->id}/merge", ['into_id' => $target->id])->assertOk();

        expect($sector->refresh()->status)->toBe(SectorStatus::Merged)->and($sector->merged_into_id)->toBe($target->id);
    });

    it('refuse une cible inactive, inconnue, ou un rôle sans le droit', function () {
        [$source, $inactive] = RegionalDirectorate::factory()->count(2)->create();
        $inactive->forceFill(['active' => false])->save();

        inSpace(referentialsManager())->postJson("/api/v1/referentials/regional-directorates/{$source->id}/merge", ['into_id' => $inactive->id])
            ->assertUnprocessable()->assertJsonPath('code', 'merge_target_inactive');
        inSpace(referentialsManager())->postJson("/api/v1/referentials/regional-directorates/{$source->id}/merge", ['into_id' => strtolower((string) Str::ulid())])->assertNotFound();
        inSpace(referentialsManager())->postJson("/api/v1/referentials/regional-directorates/{$source->id}/merge", [])->assertUnprocessable()->assertJsonValidationErrors('into_id');
        inSpace(Membership::factory()->role(Role::CgaManager)->create())->postJson("/api/v1/referentials/regional-directorates/{$source->id}/merge", ['into_id' => $inactive->id])->assertForbidden();
    });
});
