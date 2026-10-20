<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\Holiday;
use App\Domain\Referentials\Models\NationalReference;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Calendar\BusinessCalendar;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\Spa;

/*
 * Référentiels nationaux (06-IMPLEMENTATION-PLAN étape 1.1, F-REF-01 à 04) : CRUD par le CNCGA
 * (droit referentials.manage), lecture dans tout espace ; une valeur utilisée ne se supprime pas
 * (RG-DATA-05).
 */

function cncgaManager(): Membership
{
    return Membership::factory()->role(Role::CncgaManager)->create();
}

/**
 * Pour chaque référentiel : adresse, création valide, champ refusé, valeur existante, modification,
 * et une donnée qui l'utilise (null si rien ne peut l'utiliser).
 *
 * @return array<string, array{uri: string, create: Closure(): array<string, mixed>, invalid: array{0: array<string, mixed>, 1: string}, make: Closure(): Model, update: Closure(Model): array<string, mixed>, use: Closure(Model): void|null}>
 */
function referentials(): array
{
    return [
        'directions régionales' => [
            'uri' => 'regional-directorates',
            'create' => fn () => ['code' => 'DR-ABJ-N', 'name' => 'DR Abidjan Nord', 'geo_key' => 'abidjan-nord', 'active' => true],
            'invalid' => [['code' => 'avec espace', 'name' => ''], 'code'],
            'make' => fn () => RegionalDirectorate::factory()->create(),
            'update' => fn (Model $item) => ['code' => $item->getAttribute('code'), 'name' => 'Nom modifié', 'geo_key' => null, 'active' => false],
            'use' => fn (Model $item) => TaxOffice::factory()->create(['regional_directorate_id' => $item->getKey()]),
        ],
        'centres des impôts' => [
            'uri' => 'tax-offices',
            'create' => fn () => ['regional_directorate_id' => RegionalDirectorate::factory()->create()->id, 'code' => 'CDI-PLT', 'name' => 'CDI Plateau'],
            'invalid' => [['regional_directorate_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'code' => 'CDI-X', 'name' => 'X'], 'regional_directorate_id'],
            'make' => fn () => TaxOffice::factory()->create(),
            'update' => fn (Model $item) => ['regional_directorate_id' => $item->getAttribute('regional_directorate_id'), 'code' => $item->getAttribute('code'), 'name' => 'Nom modifié', 'active' => false],
            'use' => fn (Model $item) => Organization::factory()->create(['tax_office_id' => $item->getKey()]),
        ],
        'communes' => [
            'uri' => 'communes',
            'create' => fn () => ['name' => 'Yopougon', 'regional_directorate_id' => null],
            'invalid' => [['name' => str_repeat('a', 121)], 'name'],
            'make' => fn () => Commune::factory()->create(),
            'update' => fn (Model $item) => ['name' => 'Nom modifié', 'regional_directorate_id' => RegionalDirectorate::factory()->create()->id],
            'use' => fn (Model $item) => Organization::factory()->create(['commune_id' => $item->getKey()]),
        ],
        'secteurs' => [
            'uri' => 'sectors',
            'create' => fn () => ['code' => 'ART001', 'name' => 'Artisanat'],
            'invalid' => [['code' => 'TROP-LONG-CODE', 'name' => 'X'], 'code'],
            'make' => fn () => Sector::factory()->create(),
            'update' => fn (Model $item) => ['code' => $item->getAttribute('code'), 'name' => 'Nom modifié'],
            'use' => fn (Model $item) => Sector::factory()->create(['status' => 'merged', 'merged_into_id' => $item->getKey()]),
        ],
        'jours fériés' => [
            'uri' => 'holidays',
            'create' => fn () => ['date' => '2040-04-10', 'label' => 'Fête d\'essai'],
            'invalid' => [['date' => '10/04/2040', 'label' => 'X'], 'date'],
            'make' => fn () => Holiday::factory()->create(),
            'update' => fn (Model $item) => ['date' => '2041-01-02', 'label' => 'Nom modifié'],
            'use' => null,
        ],
        'références nationales' => [
            'uri' => 'national-references',
            'create' => fn () => ['year' => 2026, 'domestic_tax_revenue' => 5_000_000_000_000, 'nominal_gdp' => 50_000_000_000_000, 'tax_revenue_source' => 'Loi de finances 2026', 'gdp_source' => 'INS'],
            'invalid' => [['year' => 2026, 'nominal_gdp' => -1], 'nominal_gdp'],
            'make' => fn () => NationalReference::factory()->create(),
            'update' => fn (Model $item) => ['year' => $item->getAttribute('year'), 'domestic_tax_revenue' => 1, 'nominal_gdp' => 2, 'tax_revenue_source' => null, 'gdp_source' => null],
            'use' => null,
        ],
    ];
}

dataset('référentiels', fn () => array_map(fn (array $referential): array => [$referential], referentials()));

it('liste un référentiel dans tout espace, paginé, sans droit de gestion hors du CNCGA', function (array $referential) {
    $referential['make']();
    $referential['make']();

    inSpace(Membership::factory()->create())
        ->getJson("/api/v1/referentials/{$referential['uri']}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('data.0.can', ['update' => false, 'delete' => false]);
})->with('référentiels');

it('montre une valeur, et répond 404 à une valeur inconnue', function (array $referential) {
    $item = $referential['make']();

    inSpace(cncgaManager())->getJson("/api/v1/referentials/{$referential['uri']}/{$item->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', $item->getKey())
        ->assertJsonPath('data.can', ['update' => true, 'delete' => true]);
    inSpace(cncgaManager())->getJson("/api/v1/referentials/{$referential['uri']}/0")->assertNotFound();
})->with('référentiels');

it('crée une valeur avec le droit referentials.manage, et l\'inscrit au journal des modifications', function (array $referential) {
    $response = inSpace(cncgaManager())->postJson("/api/v1/referentials/{$referential['uri']}", $referential['create']())
        ->assertCreated();

    expect(AuditLog::query()->where('event', 'created')->where('subject_id', (string) $response->json('data.id'))->exists())->toBeTrue();
})->with('référentiels');

it('refuse la gestion à un rôle sans le droit referentials.manage', function (array $referential, Role $role) {
    $item = $referential['make']();
    $member = Membership::factory()->role($role)->create();

    inSpace($member)->postJson("/api/v1/referentials/{$referential['uri']}", $referential['create']())->assertForbidden()->assertJsonPath('code', 'forbidden');
    inSpace($member)->putJson("/api/v1/referentials/{$referential['uri']}/{$item->getKey()}", $referential['update']($item))->assertForbidden();
    inSpace($member)->deleteJson("/api/v1/referentials/{$referential['uri']}/{$item->getKey()}")->assertForbidden();
})->with('référentiels')->with([Role::CgaManager, Role::CncgaViewer, Role::CncgaTraining]);

it('laisse aussi la Présidente et l\'administrateur de la plateforme gérer les référentiels', function (Role $role) {
    inSpace(Membership::factory()->role($role)->create())
        ->postJson('/api/v1/referentials/sectors', ['code' => 'ART002', 'name' => 'Artisanat d\'art'])
        ->assertCreated();
})->with([Role::CncgaPresident, Role::PlatformAdmin]);

it('valide la saisie', function (array $referential) {
    [$payload, $field] = $referential['invalid'];

    inSpace(cncgaManager())->postJson("/api/v1/referentials/{$referential['uri']}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with('référentiels');

it('modifie une valeur', function (array $referential) {
    $item = $referential['make']();
    $payload = $referential['update']($item);

    $response = inSpace(cncgaManager())->putJson("/api/v1/referentials/{$referential['uri']}/{$item->getKey()}", $payload)->assertOk();

    $field = array_key_exists('name', $payload) ? 'name' : (array_key_exists('label', $payload) ? 'label' : 'domestic_tax_revenue');
    expect($response->json("data.{$field}"))->toBe($payload[$field]);
})->with('référentiels');

it('supprime une valeur inutilisée', function (array $referential) {
    $item = $referential['make']();

    inSpace(cncgaManager())->deleteJson("/api/v1/referentials/{$referential['uri']}/{$item->getKey()}")->assertNoContent();

    expect($item::query()->whereKey($item->getKey())->exists())->toBeFalse();
})->with('référentiels');

it('RG-DATA-05 refuse de supprimer une valeur utilisée', function (array $referential) {
    if ($referential['use'] === null) {
        expect(true)->toBeTrue();

        return;
    }
    $item = $referential['make']();
    $referential['use']($item);

    inSpace(cncgaManager())->deleteJson("/api/v1/referentials/{$referential['uri']}/{$item->getKey()}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'referential_in_use');
    expect($item::query()->whereKey($item->getKey())->exists())->toBeTrue();
})->with('référentiels');

it('exige une session et un espace', function (array $referential) {
    $this->withHeaders(Spa::headers())->getJson("/api/v1/referentials/{$referential['uri']}")->assertUnauthorized();
    $this->actingAs(Membership::factory()->create()->user)->withHeaders(Spa::headers())
        ->getJson("/api/v1/referentials/{$referential['uri']}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'space_required');
})->with('référentiels');

describe('listes : filtres, tri, pagination (02-TRD §4.3)', function () {
    it('cherche sans tenir compte de la casse ni des accents', function () {
        RegionalDirectorate::factory()->create(['name' => 'DR Côte Ouest']);
        RegionalDirectorate::factory()->create(['name' => 'DR Abidjan Sud']);

        inSpace(cncgaManager())->getJson('/api/v1/referentials/regional-directorates?filter[search]=cote')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'DR Côte Ouest');
    });

    it('prend littéralement les jokers % et _ de la recherche', function () {
        Commune::factory()->create(['name' => 'Commune 100%']);
        Commune::factory()->create(['name' => 'Commune 1000']);

        inSpace(cncgaManager())->getJson('/api/v1/referentials/communes?filter[search]='.rawurlencode('100%'))
            ->assertOk()->assertJsonCount(1, 'data');
    });

    it('filtre les CDI par direction régionale et par état, et trie', function () {
        $dr = RegionalDirectorate::factory()->create();
        TaxOffice::factory()->create(['regional_directorate_id' => $dr->id, 'name' => 'CDI B']);
        TaxOffice::factory()->create(['regional_directorate_id' => $dr->id, 'name' => 'CDI A']);
        TaxOffice::factory()->create(['regional_directorate_id' => $dr->id, 'name' => 'CDI C', 'active' => false]);
        TaxOffice::factory()->create();

        inSpace(cncgaManager())->getJson("/api/v1/referentials/tax-offices?filter[regional_directorate_id]={$dr->id}&filter[active]=true&sort=-name")
            ->assertOk()
            ->assertJsonPath('data.*.name', ['CDI B', 'CDI A'])
            ->assertJsonPath('data.0.regional_directorate.code', $dr->code);
    });

    it('pagine selon les paramètres de la plateforme', function () {
        app(SettingsService::class)->set('pagination.default', 5);
        Sector::factory()->count(7)->create();

        inSpace(cncgaManager())->getJson('/api/v1/referentials/sectors?page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 2);
        inSpace(cncgaManager())->getJson('/api/v1/referentials/sectors?per_page=3')->assertJsonCount(3, 'data');
    });

    it('filtre les jours fériés par année et les trie par date', function () {
        Holiday::factory()->create(['date' => '2040-12-25', 'label' => 'Noël']);
        Holiday::factory()->create(['date' => '2040-01-01', 'label' => 'Jour de l\'an']);
        Holiday::factory()->create(['date' => '2041-01-01', 'label' => 'Jour de l\'an']);

        inSpace(cncgaManager())->getJson('/api/v1/referentials/holidays?filter[year]=2040')
            ->assertOk()->assertJsonPath('data.*.date', ['2040-01-01', '2040-12-25']);
    });

    it('refuse un filtre, un tri ou une taille de page hors de la liste permise', function (string $query, string $field) {
        inSpace(cncgaManager())->getJson("/api/v1/referentials/communes?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'filtre inconnu' => ['filter[secret]=1', 'filter'],
        'tri inconnu' => ['sort=created_at', 'sort'],
        'page trop grande' => ['per_page=101', 'per_page'],
        'identifiant mal formé' => ['filter[regional_directorate_id]=abc', 'filter.regional_directorate_id'],
    ]);
});

describe('secteurs (RG-REF-01)', function () {
    it('montre à un CGA les secteurs validés et ceux qu\'il a proposés, au CNCGA tous', function () {
        $cga = Membership::factory()->role(Role::CgaManager)->create();
        Sector::factory()->create(['name' => 'Validé']);
        Sector::factory()->proposedBy($cga->organization)->create(['name' => 'Proposé par moi']);
        $other = Sector::factory()->proposedBy(Organization::factory()->create())->create(['name' => 'Proposé par un autre']);
        Sector::factory()->rejected()->create(['name' => 'Rejeté']);

        expect(inSpace($cga)->getJson('/api/v1/referentials/sectors?sort=name')->json('data.*.name'))->toBe(['Proposé par moi', 'Validé'])
            ->and(inSpace(cncgaManager())->getJson('/api/v1/referentials/sectors')->json('meta.total'))->toBe(4);
        inSpace($cga)->getJson("/api/v1/referentials/sectors/{$other->id}")->assertNotFound();
    });

    it('valide d\'emblée un secteur créé par le CNCGA, au nom de son auteur', function () {
        $manager = cncgaManager();

        inSpace($manager)->postJson('/api/v1/referentials/sectors', ['code' => 'ART001', 'name' => 'Artisanat'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.status_label', 'Validé');

        expect(Sector::query()->where('code', 'ART001')->sole())
            ->validated_by->toBe($manager->user_id)
            ->validated_at->not->toBeNull();
    });
});

it('F-REF-04 retient l\'auteur des références nationales', function () {
    $manager = cncgaManager();

    $response = inSpace($manager)->postJson('/api/v1/referentials/national-references', ['year' => 2026, 'nominal_gdp' => 1])->assertCreated();

    expect(NationalReference::query()->findOrFail($response->json('data.id'))->updated_by)->toBe($manager->user_id);
});

it('RG-OBL-06 tient compte d\'un jour férié ajouté dès sa création', function () {
    $calendar = app(BusinessCalendar::class);
    expect($calendar->isBusinessDay(CarbonImmutable::parse('2040-04-10')))->toBeTrue();

    inSpace(cncgaManager())->postJson('/api/v1/referentials/holidays', ['date' => '2040-04-10', 'label' => 'Fête d\'essai'])->assertCreated();

    expect(app(BusinessCalendar::class)->isBusinessDay(CarbonImmutable::parse('2040-04-10')))->toBeFalse();
});

it('refuse un code déjà pris', function () {
    RegionalDirectorate::factory()->create(['code' => 'DR-1']);

    inSpace(cncgaManager())->postJson('/api/v1/referentials/regional-directorates', ['code' => 'DR-1', 'name' => 'Doublon'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
});
