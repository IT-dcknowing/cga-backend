<?php

use App\Domain\Obligations\Actions\LoadObligationCatalog;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Enums\ObligationPeriodicity;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\ObligationCatalogSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/*
 * Catalogue des obligations (06-IMPLEMENTATION-PLAN étape 4.1, F-OBL-01, RG-OBL-01, RG-DATA-02) :
 * chargement du référentiel fourni en JSON (ici un jeu d'essai, jamais le catalogue de référence),
 * contrôle avant écriture, rejouable, version provisoire ; visibilité du catalogue national et des
 * obligations locales d'un CGA.
 */

const OBLIGATION_FIXTURE = __DIR__.'/../../Fixtures/obligations/catalog.json';

/**
 * @return array<string, mixed>
 */
function obligationFixture(): array
{
    return json_decode((string) file_get_contents(OBLIGATION_FIXTURE), true);
}

it('F-OBL-01 · charge le référentiel fourni : version, catégories, règles d\'échéance, applicabilité, provisoire', function () {
    config(['cga.seed.obligations.catalog_path' => OBLIGATION_FIXTURE]);

    $this->seed(ObligationCatalogSeeder::class);

    expect(ObligationType::query()->count())->toBe(3);

    $tee = ObligationType::query()->where('code', 'ESSAI-TEE')->sole();

    expect($tee->catalog_version)->toBe('2026.1')
        ->and($tee->isNational())->toBeTrue()
        ->and($tee->category)->toBe(ObligationCategory::Tax)
        ->and($tee->kind)->toBe(ObligationKind::Payment)
        ->and($tee->periodicity)->toBe(ObligationPeriodicity::Yearly)
        ->and($tee->due_rule)->toEqual(['type' => 'fixed_date', 'month' => 1, 'day' => 30])
        ->and($tee->applicability)->toEqual(['regimes' => ['TEE'], 'min_employees' => null, 'sectors' => []])
        ->and($tee->tax_nature)->toBe(TaxNature::Tee)
        ->and($tee->has_abatement)->toBeTrue()
        ->and($tee->creates_payment_on_done)->toBeFalse()
        ->and($tee->legal_basis)->toBe('Texte d\'essai')
        ->and($tee->data_status)->toBe(DataStatus::Provisional);

    $social = ObligationType::query()->where('code', 'ESSAI-SOC')->sole();

    expect($social->applicability['min_employees'])->toBe(1)
        ->and($social->has_abatement)->toBeFalse()
        ->and($social->creates_payment_on_done)->toBeTrue();
});

it('RG-DATA-02 · rejouable : aucune obligation en double, une obligation validée le reste', function () {
    config(['cga.seed.obligations.catalog_path' => OBLIGATION_FIXTURE]);
    $this->seed(ObligationCatalogSeeder::class);

    $tva = ObligationType::query()->where('code', 'ESSAI-TVA')->sole();
    $tva->data_status = DataStatus::Validated;
    $tva->label = 'Libellé corrigé à l\'écran';
    $tva->save();

    $this->seed(ObligationCatalogSeeder::class);

    expect(ObligationType::query()->count())->toBe(3)
        ->and($tva->refresh()->data_status)->toBe(DataStatus::Validated)
        ->and($tva->label)->toBe('Déclaration d\'essai mensuelle (RME)');
});

it('sans fichier : le catalogue reste vide, aucune obligation n\'est inventée', function () {
    config(['cga.seed.obligations.catalog_path' => sys_get_temp_dir().'/absent-'.uniqid().'.json']);

    $this->seed(ObligationCatalogSeeder::class);

    expect(ObligationType::query()->count())->toBe(0);
});

it('refuse un fichier illisible', function () {
    $path = sys_get_temp_dir().'/catalogue-'.uniqid().'.json';
    File::put($path, '{pas du json');
    config(['cga.seed.obligations.catalog_path' => $path]);

    try {
        expect(fn () => $this->seed(ObligationCatalogSeeder::class))->toThrow(RuntimeException::class);
    } finally {
        File::delete($path);
    }
});

it('refuse un catalogue invalide sans rien écrire : catégorie, règle d\'échéance, régime, code en double', function (Closure $break, string $field) {
    $catalog = obligationFixture();
    $break($catalog);

    try {
        app(LoadObligationCatalog::class)->execute($catalog);
        $this->fail('Le catalogue invalide a été chargé.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain($field);
    }

    expect(ObligationType::query()->count())->toBe(0);
})->with([
    'catégorie inconnue' => [fn (array &$catalog) => $catalog['obligations'][0]['category'] = 'impots', 'obligations.0.category'],
    'règle inconnue' => [fn (array &$catalog) => $catalog['obligations'][0]['due_rule'] = ['type' => 'tous_les_jours'], 'obligations.0.due_rule.type'],
    'jour manquant' => [fn (array &$catalog) => $catalog['obligations'][0]['due_rule'] = ['type' => 'day_of_next_month'], 'obligations.0.due_rule.day'],
    'mois hors bornes' => [fn (array &$catalog) => $catalog['obligations'][2]['due_rule'] = ['type' => 'fixed_date', 'month' => 13, 'day' => 1], 'obligations.2.due_rule.month'],
    'délai manquant' => [fn (array &$catalog) => $catalog['obligations'][2]['due_rule'] = ['type' => 'day_after_period_end'], 'obligations.2.due_rule.days'],
    'régime inconnu' => [fn (array &$catalog) => $catalog['obligations'][0]['applicability']['regimes'] = ['XYZ'], 'obligations.0.applicability.regimes.0'],
    'effectif négatif' => [fn (array &$catalog) => $catalog['obligations'][1]['applicability']['min_employees'] = -1, 'obligations.1.applicability.min_employees'],
    'code en double' => [fn (array &$catalog) => $catalog['obligations'][1]['code'] = 'ESSAI-TVA', 'obligations.0.code'],
    'version absente' => [fn (array &$catalog) => $catalog['version'] = '', 'version'],
    'aucune obligation' => [fn (array &$catalog) => $catalog['obligations'] = [], 'obligations'],
]);

it('RG-OBL-01 · ne touche jamais aux obligations locales d\'un CGA, même de même code', function () {
    $cga = Organization::factory()->create();
    $local = ObligationType::factory()->localTo($cga)->create(['code' => 'ESSAI-TVA', 'label' => 'Obligation locale']);

    app(LoadObligationCatalog::class)->execute(obligationFixture());

    expect($local->refresh()->label)->toBe('Obligation locale')
        ->and(ObligationType::query()->where('code', 'ESSAI-TVA')->count())->toBe(2);
});

it('RG-OBL-01 · un CGA voit le catalogue national et ses obligations locales, jamais celles d\'un autre CGA', function () {
    $national = ObligationType::factory()->create();
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $own = ObligationType::factory()->localTo($manager->organization)->create();
    $other = ObligationType::factory()->localTo(Organization::factory()->create())->create();
    $context = app(TenantContext::class);

    $context->set($manager);
    expect(ObligationType::query()->visibleIn($context)->pluck('id')->all())
        ->toEqualCanonicalizing([$national->id, $own->id]);

    $context->set(Membership::factory()->role(Role::CncgaManager)->create());
    expect(ObligationType::query()->visibleIn($context)->pluck('id')->all())->toBe([$national->id])
        ->and($other->isNational())->toBeFalse();
});
