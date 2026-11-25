<?php

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Obligations\Support\ApplicabilityEngine;
use Illuminate\Support\Collection;

/*
 * Moteur d'applicabilité des obligations (06-IMPLEMENTATION-PLAN étape 4.2, RG-OBL-02, 03) : régime,
 * effectif salarié, secteur ; une liste vide ne restreint rien ; CNPS et CMU exigent un effectif.
 * Tests sans base de données : le moteur ne lit que l'obligation et les données du dossier.
 */

/**
 * @param  array<string, mixed>  $applicability
 */
function engineType(array $applicability = [], ObligationCategory $category = ObligationCategory::Tax, bool $active = true): ObligationType
{
    $type = new ObligationType;
    $type->category = $category;
    $type->active = $active;
    $type->applicability = [...['regimes' => [], 'min_employees' => null, 'sectors' => []], ...$applicability];

    return $type;
}

it('RG-OBL-02 · sans condition, une obligation s\'applique à tout dossier', function () {
    expect((new ApplicabilityEngine)->applies(engineType(), TaxRegime::Tee, null, null))->toBeTrue()
        ->and((new ApplicabilityEngine)->applies(engineType(), null, 0, 'COM001'))->toBeTrue();
});

it('RG-OBL-02 · le régime du dossier doit figurer dans la liste ; sans régime connu, rien ne s\'applique', function () {
    $engine = new ApplicabilityEngine;
    $rme = engineType(['regimes' => ['RME']]);

    expect($engine->applies($rme, TaxRegime::Rme, 0, null))->toBeTrue()
        ->and($engine->applies($rme, TaxRegime::Tee, 0, null))->toBeFalse()
        ->and($engine->applies($rme, null, 0, null))->toBeFalse()
        ->and($engine->applies(engineType(['regimes' => ['RME', 'TEE']]), TaxRegime::Tee, 0, null))->toBeTrue();
});

it('RG-OBL-02 · effectif minimal : atteint ou dépassé ; un effectif inconnu vaut zéro', function () {
    $engine = new ApplicabilityEngine;
    $five = engineType(['min_employees' => 5]);

    expect($engine->applies($five, TaxRegime::Rme, 5, null))->toBeTrue()
        ->and($engine->applies($five, TaxRegime::Rme, 12, null))->toBeTrue()
        ->and($engine->applies($five, TaxRegime::Rme, 4, null))->toBeFalse()
        ->and($engine->applies($five, TaxRegime::Rme, null, null))->toBeFalse()
        ->and($engine->applies(engineType(['min_employees' => 0]), TaxRegime::Rme, null, null))->toBeTrue();
});

it('RG-OBL-02 · secteur : le code du secteur du dossier doit figurer dans la liste', function () {
    $engine = new ApplicabilityEngine;
    $transport = engineType(['sectors' => ['TRA001']]);

    expect($engine->applies($transport, TaxRegime::Tee, 0, 'TRA001'))->toBeTrue()
        ->and($engine->applies($transport, TaxRegime::Tee, 0, 'COM001'))->toBeFalse()
        ->and($engine->applies($transport, TaxRegime::Tee, 0, null))->toBeFalse();
});

it('RG-OBL-03 · CNPS et CMU : seulement avec au moins un salarié, quelles que soient les autres conditions', function (ObligationCategory $category) {
    $engine = new ApplicabilityEngine;
    $social = engineType(category: $category);

    expect($engine->applies($social, TaxRegime::Tee, 0, null))->toBeFalse()
        ->and($engine->applies($social, TaxRegime::Tee, null, null))->toBeFalse()
        ->and($engine->applies($social, TaxRegime::Tee, 1, null))->toBeTrue()
        ->and($engine->applies(engineType(category: ObligationCategory::Other), TaxRegime::Tee, 0, null))->toBeTrue();
})->with([ObligationCategory::Cnps, ObligationCategory::Cmu]);

it('toutes les conditions à la fois ; une obligation inactive ne s\'applique jamais', function () {
    $engine = new ApplicabilityEngine;
    $all = engineType(['regimes' => ['RME'], 'min_employees' => 2, 'sectors' => ['BTP001']]);

    expect($engine->applies($all, TaxRegime::Rme, 2, 'BTP001'))->toBeTrue()
        ->and($engine->applies($all, TaxRegime::Tee, 2, 'BTP001'))->toBeFalse()
        ->and($engine->applies($all, TaxRegime::Rme, 1, 'BTP001'))->toBeFalse()
        ->and($engine->applies($all, TaxRegime::Rme, 2, 'COM001'))->toBeFalse()
        ->and($engine->applies(engineType(active: false), TaxRegime::Rme, 2, null))->toBeFalse();
});

it('filtre une liste d\'obligations candidates', function () {
    $tee = engineType(['regimes' => ['TEE']]);
    $rme = engineType(['regimes' => ['RME']]);
    $cnps = engineType(category: ObligationCategory::Cnps);

    $kept = (new ApplicabilityEngine)->filter(new Collection([$tee, $rme, $cnps]), TaxRegime::Tee, 3, null);

    expect($kept->all())->toBe([$tee, $cnps]);
});
