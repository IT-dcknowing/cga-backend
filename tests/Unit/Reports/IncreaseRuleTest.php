<?php

use App\Domain\Reports\Support\IncreaseRule;

/*
 * Règle « cotisation augmentée » (06-IMPLEMENTATION-PLAN étape 5.6b, RG-RPT-08) : seuil minimal en %,
 * 0 % = toute hausse ; sans comparaison possible, pas de hausse.
 */

it('RG-RPT-08 · toute hausse compte avec un seuil de 0 %', function () {
    expect(IncreaseRule::isIncrease(80_000, 90_000, 0))->toBeTrue()
        ->and(IncreaseRule::isIncrease(80_000, 80_001, 0))->toBeTrue()
        ->and(IncreaseRule::isIncrease(80_000, 80_000, 0))->toBeFalse()
        ->and(IncreaseRule::isIncrease(80_000, 70_000, 0))->toBeFalse();
});

it('RG-RPT-08 · le seuil minimal s\'applique en pourcentage de la période de comparaison', function () {
    expect(IncreaseRule::isIncrease(100_000, 110_000, 10))->toBeTrue()
        ->and(IncreaseRule::isIncrease(100_000, 109_000, 10))->toBeFalse()
        ->and(IncreaseRule::percent(80_000, 90_000))->toBe(12.5);
});

it('RG-RPT-08 · sans valeur de comparaison positive, aucune hausse', function () {
    expect(IncreaseRule::isIncrease(null, 90_000, 0))->toBeFalse()
        ->and(IncreaseRule::isIncrease(0, 90_000, 0))->toBeFalse()
        ->and(IncreaseRule::isIncrease(80_000, null, 0))->toBeFalse()
        ->and(IncreaseRule::percent(0, 10))->toBe(0.0);
});
